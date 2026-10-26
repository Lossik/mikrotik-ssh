<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests;

use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\Query;
use Lossik\DeviceCommunication\Result;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\DeviceCommunication\TrapException;
use Lossik\DeviceCommunication\UnsupportedOperationException;
use Lossik\Mikrotik\Ssh\SshTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The path selection and the flag pass of SshTransport against a fake SSH channel (the device is what the library
 * does not control); the first exec of a connection is always the version query.
 */
final class SshTransportTest extends TestCase
{
    private const VERSION = ':put [/system resource get version]';

    private const FIND_DISABLED = ':put [/ip firewall address-list find where disabled~"^true\\$"]';

    private const FIND_DYNAMIC = ':put [/ip firewall address-list find where dynamic~"^true\\$"]';

    private const FIND_RUNNING = ':put [/ip firewall address-list find where running~"^true\\$"]';

    /** O2 */
    public function testVersionIsAskedOnceBeforeTheFirstCommand(): void
    {
        $identity = ConsoleFixture::load('6.49.23-ser3-identity');
        $ssh = new FakeSsh(["6.49.23 (stable)\r\n", $identity, $identity]);
        $transport = self::transport($ssh);

        $transport->execute(new Command('/system/identity', 'print'));
        $transport->execute(new Command('/system/identity', 'print'));

        $print = ':local r [/system identity print as-value]; ' . CliCompilerTest::SER3;
        self::assertSame([self::VERSION, $print, $print], $ssh->commands);
        self::assertSame('text', $transport->consolePath());
    }

    /**
     * O3: whatever the version query prints that is not a version picks the text path, without an exception.
     *
     * @return iterable<string, array{string}>
     */
    public static function unreadableVersions(): iterable
    {
        yield 'error text of the console' => ["bad command name version (line 1 column 22)\n"];
        yield 'nothing' => [''];
        yield 'invalid UTF-8' => ["\xF8\xFF"];
    }

    #[DataProvider('unreadableVersions')]
    public function testUnreadableVersionPicksTheTextPath(string $version): void
    {
        $ssh = new FakeSsh([$version, ConsoleFixture::load('6.49.23-ser3-identity')]);
        $transport = self::transport($ssh);

        $result = $transport->execute(new Command('/system/identity', 'print'));

        self::assertSame('text', $transport->consolePath());
        self::assertSame(':local r [/system identity print as-value]; ' . CliCompilerTest::SER3, $ssh->commands[1]);
        self::assertSame([['name' => 'MikroTik']], $result->rows);
    }

    public function testTimeoutOnVersionProbeClosesTransportAndPropagates(): void
    {
        $ssh = new FakeSsh([false]);
        $ssh->fakeTimeout = true;
        $transport = self::transport($ssh);
        try {
            $transport->execute(new Command('/system/identity', 'print'));
            self::fail('Expected timeout');
        } catch (TimeoutException) {
            self::assertFalse($transport->isOpen());
            self::assertCount(1, $ssh->commands);
        }
    }

    public function testClosedChannelOnVersionProbeClosesTransportAndPropagates(): void
    {
        $ssh = new FakeSsh([false]);
        $transport = self::transport($ssh);
        try {
            $transport->execute(new Command('/system/identity', 'print'));
            self::fail('Expected connection exception');
        } catch (ConnectionException) {
            self::assertFalse($transport->isOpen());
            self::assertCount(1, $ssh->commands);
        }
    }

    /**
     * O15 (Q12 (a)) on both paths and F-C2 m1: refused before anything is sent, the version query included.
     *
     * @return iterable<string, array{Command}>
     */
    public static function inexpressible(): iterable
    {
        $operands = [
            'b', 'x', '', '-1', '+5', '1.5', '007', '1h', '10.0.0.0/24', '2001:db8::1', '256.0.0.1', '01.2.3.4',
        ];
        foreach ($operands as $value) {
            yield 'less ' . $value => [new Command('/ip/service', 'print', [], Query::less('port', $value))];
            yield 'greater ' . $value => [new Command('/ip/service', 'print', [], Query::greater('port', $value))];
        }
        yield 'the root menu' => [new Command('/', 'print')];
    }

    #[DataProvider('inexpressible')]
    public function testWhatTheLineCannotExpressSendsNothing(Command $command): void
    {
        $ssh = new FakeSsh([]);
        try {
            self::transport($ssh)->execute($command);
            self::fail('UnsupportedOperationException expected');
        } catch (UnsupportedOperationException) {
            self::assertSame([], $ssh->commands);
        }
    }

    /**
     * O12 on the text path (plan §3.1): measured on 6.49.23, `c-out-6.49.23-console.txt` v-set-ok, a-put-wrapped.
     *
     * @return iterable<string, array{Command, string, Result}>
     */
    public static function textAnswers(): iterable
    {
        yield 'set, nothing printed' => [
            new Command('/ip/firewall/address-list', 'set', ['.id' => '*1', 'comment' => 'ok']),
            '',
            new Result([]),
        ];
        yield 'add, the ID in lower case' => [
            new Command('/ip/firewall/address-list', 'add', ['list' => 'q', 'address' => '10.1.0.3']),
            "*1b\r\n",
            new Result([], '*1B'),
        ];
    }

    #[DataProvider('textAnswers')]
    public function testAnswerOfAVerbBelow717IsTheResultOfTheApi(Command $command, string $output, Result $result): void
    {
        $ssh = new FakeSsh(["6.49.23 (stable)\r\n", $output]);

        self::assertEquals($result, self::transport($ssh)->execute($command));
    }

    /**
     * O12 and F-C2 M3 on the text path: `c-out-6.49.23-console.txt` v-set-bad-two, v-add-dup-put; an `add` that prints
     * no ID did not add anything the caller can address.
     *
     * @return iterable<string, array{Command, string}>
     */
    public static function textRefusals(): iterable
    {
        yield 'set of an unknown item' => [
            new Command('/ip/firewall/address-list', 'set', ['.id' => '*1,*FFFF', 'comment' => 'ok2']),
            "no such item (4)\n",
        ];
        yield 'add of a duplicate' => [
            new Command('/ip/firewall/address-list', 'add', ['list' => 'p', 'address' => '10.0.0.1']),
            "failure: already have such entry\n",
        ];
        yield 'add, nothing printed' => [
            new Command('/ip/firewall/address-list', 'add', ['list' => 'p', 'address' => '10.0.0.1']),
            '',
        ];
    }

    #[DataProvider('textRefusals')]
    public function testRefusalOfAVerbBelow717IsATrap(Command $command, string $output): void
    {
        $ssh = new FakeSsh(["6.49.23 (stable)\r\n", $output]);
        $transport = self::transport($ssh);
        try {
            $transport->execute($command);
            self::fail('TrapException expected');
        } catch (TrapException $e) {
            self::assertNull($e->category);
            self::assertSame([], $e->traps());
            self::assertTrue($transport->isOpen());
        }
    }

    /**
     * F-C2 m2 (outside the plan): the text of a refusal is decoded from the charset like on the JSON path. No router
     * printed a cp1250 error; the measured texts (ser3-err-unwrapped, v-set-bad-two) carry one such byte here.
     *
     * @return iterable<string, array{Command, string}>
     */
    public static function encodedRefusals(): iterable
    {
        yield 'print' => [new Command('/ip/address', 'print'), "bad command name \xF8 (line 1 column 15)\n"];
        yield 'set' => [new Command('/ip/address', 'set', ['.id' => '*1']), "no such item \xF8 (4)\n"];
    }

    #[DataProvider('encodedRefusals')]
    public function testRefusalIsDecodedFromTheCharsetOfTheDevice(Command $command, string $output): void
    {
        $ssh = new FakeSsh(["6.49.23 (stable)\r\n", $output]);
        try {
            self::transport($ssh, 'windows-1250')->execute($command);
            self::fail('TrapException expected');
        } catch (TrapException $e) {
            self::assertStringContainsString(' ř ', $e->getMessage());
        }
    }

    /**
     * O13 on the text path: values converted to cp1250 before they are quoted (`\HH` of the cp1250 bytes).
     *
     * @return iterable<string, array{Command, string, string}>
     */
    public static function convertedLines(): iterable
    {
        yield 'set' => [
            new Command('/ip/firewall/address-list', 'set', ['.id' => '*1', 'comment' => 'Příliš']),
            '',
            '/ip firewall address-list set numbers=*1 comment="P\\F8\\EDli\\9A"',
        ];
        yield 'print with a condition' => [
            new Command('/ip/firewall/address-list', 'print', [], Query::where('comment', 'Příliš')),
            ConsoleFixture::load('6.49.23-ser3-empty'),
            ':local r [/ip firewall address-list print as-value where comment="P\\F8\\EDli\\9A"]; '
                . CliCompilerTest::SER3,
        ];
    }

    #[DataProvider('convertedLines')]
    public function testValuesBelow717AreSentInTheCharsetOfTheDevice(
        Command $command,
        string $output,
        string $line,
    ): void {
        $ssh = new FakeSsh(["6.49.23 (stable)\r\n", $output]);

        self::transport($ssh, 'windows-1250')->execute($command);

        self::assertSame($line, $ssh->commands[1]);
    }

    /**
     * O14 (Q4 (a)): the flags the proplist names but no row carries, by one find line per flag, on both paths.
     *
     * @return iterable<string, array{string, list<string>, list<string>, list<array<string, string>>, list<string>}>
     *         the answer to the version query, the outputs after the print line, the proplist, the rows, the find
     *         lines sent after the print line
     */
    public static function flags(): iterable
    {
        $addressList = ConsoleFixture::load('6.49.23-ser3-addresslist');
        // c-out-6.49.23-console2.txt flag-find-disabled, the same run: *6 is the disabled item
        $findDisabled = ConsoleFixture::load('6.49.23-flag-find-disabled');
        $addresses = static fn (string $flag): array => array_map(
            static fn (int $n): array => ['address' => '10.0.0.' . $n, $flag => $n === 6 ? 'true' : 'false'],
            range(1, 7),
        );
        yield '(1) a flag no row has' => [
            '6.49.23 (stable)',
            [$addressList, $findDisabled],
            ['address', 'disabled'],
            $addresses('disabled'),
            [self::FIND_DISABLED],
        ];
        // rows with *6 *8 *9 *A; find prints IDs in lower case (fl-find-disabled: *c, a-put-wrapped: *1b), no
        // measured find printed *a for these rows
        $mixedCase = [
            ['disabled' => 'false'],
            ['disabled' => 'false'],
            ['disabled' => 'false'],
            ['disabled' => 'true'],
        ];
        yield '(1b) the text path, IDs in another case, no .id asked for' => [
            '6.34 (stable)',
            [ConsoleFixture::load('6.34-s-ser3-not-str-true'), "*a\r\n"],
            ['disabled'],
            $mixedCase,
            [self::FIND_DISABLED],
        ];
        yield '(1b) the JSON path, IDs in another case, no .id asked for' => [
            '7.17.2 (stable)',
            [ConsoleFixture::load('7.17.2-sl-w-json-not-str-true'), "*a\r\n"],
            ['disabled'],
            $mixedCase,
            [self::FIND_DISABLED],
        ];
        // plan O14 (2): the shape of 7.24.5 (flags printed, false too), behind the text path
        yield '(2) the flag in the rows' => [
            '6.49.23 (stable)',
            [ConsoleFixture::load('7.24.5-ser3-addresslist')],
            ['address', 'disabled'],
            $addresses('disabled'),
            [],
        ];
        yield '(3) two flags no row has' => [
            '6.49.23 (stable)',
            [$addressList, $findDisabled, "\r\n"],
            ['disabled', 'dynamic'],
            array_map(
                static fn (int $n): array => ['disabled' => $n === 6 ? 'true' : 'false', 'dynamic' => 'false'],
                range(1, 7),
            ),
            [self::FIND_DISABLED, self::FIND_DYNAMIC],
        ];
        yield '(4) no rows' => [
            '6.49.23 (stable)',
            [ConsoleFixture::load('6.49.23-ser3-empty')],
            ['disabled'],
            [],
            [],
        ];
        yield '(5) no flag asked for' => [
            '6.49.23 (stable)',
            [$addressList],
            ['address'],
            array_map(static fn (int $n): array => ['address' => '10.0.0.' . $n], range(1, 7)),
            [],
        ];
    }

    /**
     * @param list<string> $outputs
     * @param list<string> $proplist
     * @param list<array<string, string>> $rows
     * @param list<string> $finds
     */
    #[DataProvider('flags')]
    public function testFlagsMissingInEveryRowAreAskedForByFind(
        string $version,
        array $outputs,
        array $proplist,
        array $rows,
        array $finds,
    ): void {
        $ssh = new FakeSsh([$version . "\r\n", ...$outputs]);

        $result = self::transport($ssh)->execute(
            new Command('/ip/firewall/address-list', 'print', [], null, $proplist),
        );

        self::assertSame($rows, array_map(static function (array $row): array {
            ksort($row);

            return $row;
        }, $result->rows));
        self::assertSame($finds, array_slice($ssh->commands, 2));
    }

    /**
     * M1 (gate brana-otazky-m1.md (b)): from RouterOS 7.1 the console refuses a column the menu does not have in the
     * proplist (`c-out-7.1-console.txt` pl-unknown, JSON path `g1-single-7.23.8.txt`); the print runs once more
     * without it, then the flag pass as on 6.x. A flag the menu does not have is `'false'`, another column is absent.
     * The same for any proplist on a singleton menu, which RouterOS 7.1 … 7.17.2 refuse while parsing the line
     * (F-D ssh, OL10: `expected end of command`, on the JSON path bare, before any `:onerror` runs).
     *
     * @return iterable<string, array{string, string, list<string>, list<string>, list<array<string, string>>,
     *         list<string>}> the answer to the version query, the menu, the outputs after it, the proplist, the rows,
     *         the lines sent after it
     */
    public static function unknownColumns(): iterable
    {
        $json = static fn (string $menu, string $proplist): string => ':onerror e in={ :local r; :onerror p in={ '
            . ':set r [' . $menu . '/print as-value proplist=' . $proplist . '] } do={ :if ($p ~ '
            . '"^bad parameter proplist") do={ :set r [' . $menu . '/print as-value] } else={ :error $p '
            . '} }; :put [:serialize to=json options=json.no-string-conversion $r] } do={ :put ("probe " . $e) }';
        $jsonPlain = static fn (string $menu): string => ':onerror e in={ :put [:serialize to=json '
            . 'options=json.no-string-conversion [' . $menu . '/print as-value]] } do={ :put ("probe " . $e) }';
        $jsonRefusal = "probe input does not match any value of value-name (:error; line 1)\r\n";
        $jsonRows = ConsoleFixture::load('7.17.2-sl-w-json-not-str-true');
        $jsonAddresses = ['10.8.0.1', '10.8.0.3', '10.8.0.4', '10.8.0.5'];
        $text = static fn (string $menu, string $proplist): string => ':local r [/'
            . strtr(substr($menu, 1), '/', ' ') . ' print as-value'
            . ($proplist === '' ? '' : ' proplist=' . $proplist) . ']; ' . CliCompilerTest::SER3;
        $textRows = ConsoleFixture::load('7.1-ser3-addresslist');
        $textAddresses = array_map(static fn (int $n): string => '10.0.0.' . $n, range(1, 7));
        $running = static fn (string $address): array => ['address' => $address, 'running' => 'false'];
        $only = static fn (string $address): array => ['address' => $address];
        $list = '/ip/firewall/address-list';
        yield 'JSON path, a flag the menu does not have' => [
            '7.17.2 (stable)',
            $list,
            [$jsonRefusal, $jsonRows, "\r\n"],
            ['address', 'running'],
            array_map($running, $jsonAddresses),
            [$json($list, 'address,running'), $jsonPlain($list), self::FIND_RUNNING],
        ];
        yield 'JSON path, another column the menu does not have' => [
            '7.17.2 (stable)',
            $list,
            [$jsonRefusal, $jsonRows],
            ['address', 'nosuchcol'],
            array_map($only, $jsonAddresses),
            [$json($list, 'address,nosuchcol'), $jsonPlain($list)],
        ];
        yield 'text path, a flag the menu does not have' => [
            '7.1 (stable)',
            $list,
            ["input does not match any value of value-name\n", $textRows, "\r\n"],
            ['address', 'running'],
            array_map($running, $textAddresses),
            [$text($list, 'address,running'), $text($list, ''), self::FIND_RUNNING],
        ];
        // [N] the text inside `:local r [...]` is unmeasured; a suffix like the other console errors carry
        yield 'text path, another column the menu does not have, the reason with a position' => [
            '7.16.2 (stable)',
            $list,
            ["input does not match any value of value-name (line 1 column 61)\n", $textRows],
            ['address', 'nosuchcol'],
            array_map($only, $textAddresses),
            [$text($list, 'address,nosuchcol'), $text($list, '')],
        ];
        // the refusals as measured for these very lines (fd-ssh-report.md); the plain print is the 6.49.23 fixture
        // (the SER3 form is ours, the same on every version), hence its version
        yield 'text path, a singleton menu (Client::version())' => [
            '7.16.2 (stable)',
            '/system/resource',
            ["expected end of command (line 1 column 43)\n", ConsoleFixture::load('6.49.23-ser3-resource')],
            ['version'],
            [['version' => '6.49.23 (stable)']],
            [$text('/system/resource', 'version'), $text('/system/resource', '')],
        ];
        yield 'text path, a singleton menu, several columns' => [
            '7.12.2 (stable)',
            '/ip/dns',
            ["expected end of command (line 1 column 34)\n", ConsoleFixture::load('7.24.5-d5-ser3-dns')],
            ['allow-remote-requests', 'dynamic-servers', 'servers'],
            [[
                'allow-remote-requests' => 'false',
                'dynamic-servers' => '10.0.2.3',
                'servers' => '2001:db8::1,10.0.0.1',
            ]],
            [
                $text('/ip/dns', 'allow-remote-requests,dynamic-servers,servers'),
                $text('/ip/dns', ''),
            ],
        ];
        // no JSON fixture of /system/resource: the values of `resource-plain` (fd-ssh-probe-7.17.2.txt) in the form
        // of a measured singleton object (CliCompilerTest, /system/clock)
        yield 'JSON path, a singleton menu (Client::version())' => [
            '7.17.2 (stable)',
            '/system/resource',
            [
                "expected end of command (line 1 column 83)\n",
                '{"architecture-name":"x86_64","board-name":"CHR QEMU Standard PC (i440FX + PIIX, 1996)",'
                    . '"cpu-count":2,"version":"7.17.2 (stable)"}' . "\r\n",
            ],
            ['version'],
            [['version' => '7.17.2 (stable)']],
            [$json('/system/resource', 'version'), $jsonPlain('/system/resource')],
        ];
    }

    /**
     * @param list<string> $outputs
     * @param list<string> $proplist
     * @param list<array<string, string>> $rows
     * @param list<string> $lines
     */
    #[DataProvider('unknownColumns')]
    public function testRefusedProplistIsPrintedAgainWithoutIt(
        string $version,
        string $menu,
        array $outputs,
        array $proplist,
        array $rows,
        array $lines,
    ): void {
        $ssh = new FakeSsh([$version . "\r\n", ...$outputs]);

        $result = self::transport($ssh)->execute(new Command($menu, 'print', [], null, $proplist));

        self::assertSame($rows, array_map(static function (array $row): array {
            ksort($row);

            return $row;
        }, $result->rows));
        self::assertSame($lines, array_slice($ssh->commands, 1));
    }

    /**
     * M1: the second print is only for a refusal of the proplist (an unknown column, a proplist on a singleton menu),
     * only where a proplist was sent, and once.
     *
     * @return iterable<string, array{string, list<string>, list<string>}>
     *         the answer to the version query, the outputs after it, the lines sent after it
     */
    public static function printRefusals(): iterable
    {
        $json = ':onerror e in={ :local r; :onerror p in={ :set r '
            . '[/ip/firewall/address-list/print as-value proplist=address,running] } do={ :if ($p ~ '
            . '"^bad parameter proplist") do={ :set r [/ip/firewall/address-list/print as-value] } else={ :error $p '
            . '} }; :put [:serialize to=json options=json.no-string-conversion $r] } do={ :put ("probe " . $e) }';
        $jsonPlain = ':onerror e in={ :put [:serialize to=json options=json.no-string-conversion '
            . '[/ip/firewall/address-list/print as-value]] } do={ :put ("probe " . $e) }';
        $jsonRefusal = "probe input does not match any value of value-name (:error; line 1)\r\n";
        $text = ':local r [/ip firewall address-list print as-value proplist=address,running]; '
            . CliCompilerTest::SER3;
        $textPlain = ':local r [/ip firewall address-list print as-value]; ' . CliCompilerTest::SER3;
        $textRefusal = "input does not match any value of value-name\n";
        yield 'JSON path, another refusal' => [
            '7.17.2 (stable)',
            ["probe no such item (4) (:error; line 1)\r\n"],
            [$json],
        ];
        yield 'text path, another refusal' => [
            '7.1 (stable)',
            ["syntax error (line 1 column 39)\n"],
            [$text],
        ];
        yield 'JSON path, the second print refused the same way' => [
            '7.17.2 (stable)',
            [$jsonRefusal, $jsonRefusal],
            [$json, $jsonPlain],
        ];
        yield 'text path, the second print refused the same way' => [
            '7.1 (stable)',
            [$textRefusal, $textRefusal],
            [$text, $textPlain],
        ];
        // 6.x is sent no proplist, so the refusal cannot be about one
        yield 'text path below 7.1' => [
            '6.49.23 (stable)',
            [$textRefusal],
            [$textPlain],
        ];
        yield 'text path below 7.1, the refusal a proplist on a singleton menu gets' => [
            '6.49.23 (stable)',
            ["expected end of command (line 1 column 39)\n"],
            [$textPlain],
        ];
    }

    /**
     * @param list<string> $outputs
     * @param list<string> $lines
     */
    #[DataProvider('printRefusals')]
    public function testOtherRefusalOfAPrintIsATrapWithoutAnotherPrint(
        string $version,
        array $outputs,
        array $lines,
    ): void {
        $ssh = new FakeSsh([$version . "\r\n", ...$outputs]);
        $transport = self::transport($ssh);
        try {
            $transport->execute(new Command('/ip/firewall/address-list', 'print', [], null, ['address', 'running']));
            self::fail('TrapException expected');
        } catch (TrapException) {
            self::assertTrue($transport->isOpen());
            self::assertSame($lines, array_slice($ssh->commands, 1));
        }
    }

    private static function transport(FakeSsh $ssh, string $charset = 'UTF-8'): SshTransport
    {
        $reflection = new \ReflectionClass(SshTransport::class);
        $transport = $reflection->newInstanceWithoutConstructor();
        foreach (['ssh' => $ssh, 'marker' => 'probe', 'charset' => $charset] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setValue($transport, $value);
        }
        return $transport;
    }
}
