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
 * The RouterOS console over SSH (7.13 or newer: `print` goes through `:serialize to=json`) as a Transport.
 *
 *     $transport = SshTransport::connect('10.0.0.1', 'admin', 'secret', new SshOptions(responseTimeout: 120));
 *
 * Every execute() runs one console line in its own exec channel (see CliCompiler). A line that fails is a
 * TrapException and the transport stays open. Any ConnectionException (the connection is lost, a time limit ran out)
 * closes the transport; every further call then throws ConnectionException without I/O.
 */
final class SshTransport implements Transport
{
    private ?SSH2 $ssh;

    private function __construct(SSH2 $ssh, private readonly string $marker)
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

        return new self($ssh, 'rosx-' . bin2hex(random_bytes(8)));
    }

    /**
     * @throws TrapException the console refused the line (the transport stays open)
     * @throws TimeoutException no reply within responseTimeout ('responseTimeout'); the transport is closed
     * @throws ConnectionException the transport is closed, or got closed by this call
     * @throws ProtocolException a print answered with something other than a list of rows
     * @throws UnsupportedOperationException the command or query has no CLI form (nothing is sent)
     */
    public function execute(Command $command): Result
    {
        $ssh = $this->ssh;
        if ($ssh === null) {
            throw new ConnectionException('The transport is closed');
        }
        $line = CliCompiler::compile($command, $this->marker);
        try {
            $output = $ssh->exec($line);
        } catch (\Exception $e) {
            $this->close();
            throw Connector::lost($e, 'The command');
        }
        // phpseclib returns what arrived so far when the time runs out
        if ($ssh->isTimeout()) {
            $this->close();
            throw new TimeoutException('No reply to the command in time', 'responseTimeout');
        }
        if (!is_string($output)) {
            $this->close();
            throw new ConnectionException('The device did not run the command');
        }
        try {
            return CliCompiler::result($command, $output, $this->marker);
        } catch (ConnectionException $e) {
            $this->close();
            throw $e;
        }
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
