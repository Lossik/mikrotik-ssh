<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh;

use Lossik\DeviceCommunication\AuthenticationException;
use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\ProtocolException;
use Lossik\DeviceCommunication\Result;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\DeviceCommunication\Transport;
use Lossik\DeviceCommunication\TrapException;
use Lossik\DeviceCommunication\UnsupportedOperationException;
use phpseclib3\Net\SSH2;

/**
 * The RouterOS console over SSH as a Transport: `:serialize to=json` from 7.17, a length-prefixed text form below.
 *
 *     $transport = SshTransport::connect('10.0.0.1', 'admin', 'secret', new SshOptions(responseTimeout: 120));
 *
 * Every execute() runs one console line in its own exec channel (see CliCompiler). A line that fails is a
 * TrapException and the transport stays open. Any ConnectionException (the connection is lost, a time limit ran out)
 * closes the transport; every further call then throws ConnectionException without I/O.
 *
 * With SshOptions::$charset other than UTF-8 (e.g. 'windows-1250', what 2.x wrote) the values of a command are
 * converted to it and the output back to UTF-8, as the API transport does with ApiOptions::$charset.
 */
final class SshTransport implements Transport
{
    private ?SSH2 $ssh;

    private ?string $consolePath = null;

    private ?string $routerOsVersion = null;

    private function __construct(SSH2 $ssh, private readonly string $marker, private readonly string $charset)
    {
        $this->ssh = $ssh;
    }

    /**
     * Opens the connection and logs in; the password is always sent, an empty one too.
     *
     * @throws TimeoutException the SSH banner, handshake or login did not finish within connectTimeout
     * @throws ConnectionException the device is unreachable or closed the connection
     * @throws AuthenticationException wrong user name or password
     */
    public static function connect(
        string $host,
        string $user,
        string $password,
        SshOptions $options = new SshOptions(),
    ): self {
        $ssh = Connector::open(SSH2::class, $host, $user, $password, $options);

        return new self($ssh, 'rosx-' . bin2hex(random_bytes(8)), $options->charset);
    }

    /**
     * The first call on a connection asks for the version (`:put [/system resource get version]`, one exec) and picks
     * the path until close(): RouterOS 7.17 and newer the JSON line of CliCompiler::compile(), anything else, an
     * unreadable answer included, the text line of CliCompiler::compileText() read by TextParser. consolePath()
     * tells which.
     *
     * A change of several items is not atomic, on either path, like on the API: `set numbers=*1,*FFFF` changes `*1`
     * and then fails with "no such item" for `*FFFF` (TrapException; `*1` stays changed).
     *
     * @throws TrapException the console refused the line (the transport stays open); see above for several items
     * @throws TimeoutException no reply within responseTimeout ('responseTimeout'); the transport is closed
     * @throws ConnectionException the transport is closed, or got closed by this call
     * @throws ProtocolException a print answered with something other than rows, or the output is not valid in
     *                           SshOptions::$charset; the transport is closed
     * @throws UnsupportedOperationException the command or query has no CLI form (nothing is sent)
     * @throws \InvalidArgumentException a value cannot be converted to SshOptions::$charset (nothing is sent, the
     *                                   transport stays open)
     */
    public function execute(Command $command): Result
    {
        $ssh = $this->ssh;
        if ($ssh === null) {
            throw new ConnectionException('The transport is closed');
        }
        // both lines are checked before anything is sent, the version query included: whatever either path cannot
        // express is refused without I/O on any device
        $jsonLine = CliCompiler::compile($command, $this->marker, $this->charset);
        CliCompiler::compileText($command, $this->charset, null);
        if ($this->consolePath === null) {
            $selection = ConsolePath::fromVersion($this->exec(':put [/system resource get version]', 'version query'));
            $this->consolePath = $selection['path'];
            $this->routerOsVersion = $selection['version'];
        }
        $text = $this->consolePath === 'text';
        $output = $this->exec(
            $text ? CliCompiler::compileText($command, $this->charset, $this->routerOsVersion) : $jsonLine,
            'command',
        );
        try {
            if ($text && $command->verb !== 'print') {
                return CliCompiler::textResult($command, $output, $this->charset);
            }
            if ($text) {
                $rows = TextParser::parse($output, $this->charset);
            } else {
                $result = CliCompiler::result($command, $output, $this->marker, $this->charset, false);
                if ($command->verb !== 'print') {
                    return $result;
                }
                $rows = $result->rows;
            }

            // the flags first (they need the .id of the rows), the requested columns last
            return new Result(self::projectRows($this->fillFlags($command, $rows), $command));
        } catch (ConnectionException $e) {
            // a ProtocolException: the output is not what the line prints
            $this->close();
            throw $e;
        }
    }

    /**
     * The path picked by the first execute() of the connection: 'json' (RouterOS 7.17 and newer) or 'text'; null
     * before it.
     */
    public function consolePath(): ?string
    {
        return $this->consolePath;
    }

    private function exec(string $line, string $what): string
    {
        $ssh = $this->ssh;
        if ($ssh === null) {
            throw new ConnectionException('The transport is closed');
        }
        try {
            $output = $ssh->exec($line);
        } catch (\Exception $e) {
            $this->close();
            throw Connector::lost($e, 'The ' . $what);
        }
        if ($ssh->isTimeout()) {
            $this->close();
            throw new TimeoutException('No reply to the ' . $what . ' in time', 'responseTimeout');
        }
        if (!is_string($output)) {
            $this->close();
            throw new ConnectionException('The device did not run the ' . $what);
        }
        return $output;
    }

    /**
     * The flags `disabled`, `dynamic`, `invalid`, `running` the caller names in the proplist, where the console left
     * them out. `print as-value` prints them on RouterOS 7.23.8 and 7.24.5 (also `false`), not on 7.17.2 or 6.x.
     * For a flag missing in every row, one more line asks for the IDs of the items with the flag true
     * (CliCompiler::flagQuery()): `'true'` for those, `'false'` for the other rows. IDs compare in upper case (`find`
     * prints `*c`, the rows carry `*C`); the `.id` the console prints without being asked is what the rows are
     * matched by. No rows, or the flag in some row: no line, nothing overwritten.
     *
     * A flag the menu does not have: `find` finds nothing, the rows get `'false'` where the API has no such key. Today
     * that holds where no proplist is sent, i.e. RouterOS 6.x; from 7.1 the console refuses the unknown column in the
     * proplist and the print is a TrapException (on both paths).
     *
     * @param list<array<string, string>> $rows
     * @return list<array<string, string>>
     * @throws TrapException the console refused the find line
     */
    private function fillFlags(Command $command, array $rows): array
    {
        foreach (['disabled', 'dynamic', 'invalid', 'running'] as $flag) {
            if ($rows === [] || !in_array($flag, $command->proplist, true)) {
                continue;
            }
            foreach ($rows as $row) {
                if (array_key_exists($flag, $row)) {
                    continue 2;
                }
            }
            $ids = trim($this->exec(CliCompiler::flagQuery($command->menu, $flag), 'flag query'));
            if ($ids !== '' && preg_match('/^\*[0-9A-Fa-f]+(?:;\*[0-9A-Fa-f]+)*$/D', $ids) !== 1) {
                throw new TrapException(CliCompiler::decode($ids, $this->charset));
            }
            $found = $ids === '' ? [] : array_fill_keys(array_map('strtoupper', explode(';', $ids)), true);
            foreach ($rows as &$row) {
                $id = strtoupper($row['.id'] ?? '');
                $row[$flag] = isset($found[$id]) ? 'true' : 'false';
            }
            unset($row);
        }
        return $rows;
    }

    /** @param list<array<string, string>> $rows
     *  @return list<array<string, string>>
     */
    private static function projectRows(array $rows, Command $command): array
    {
        if ($command->proplist === []) {
            return $rows;
        }
        $columns = array_flip($command->proplist);
        return array_map(static fn (array $row): array => array_intersect_key($row, $columns), $rows);
    }

    public function close(): void
    {
        $ssh = $this->ssh;
        $this->ssh = null;
        $ssh?->disconnect();
    }

    public function isOpen(): bool
    {
        return $this->ssh !== null && $this->ssh->isConnected();
    }
}
