<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests;

use Lossik\DeviceCommunication\ProtocolException;
use Lossik\DeviceCommunication\TrapException;
use Lossik\Mikrotik\Ssh\TextParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O9–O11 of the plan (task 55e7667a, plan-stare-ros.md §7.2) on the output real routers printed for the ser3 line.
 */
final class TextParserTest extends TestCase
{
    /**
     * The fixtures of /ip/firewall/address-list (list p2) hold the values of the plan's battery: `\` and `"` in a
     * value, UTF-8 (`Příliš žluťoučký`, 23 bytes), a 24-byte comment that is CRLF, `--` and `END`, the cp1250 byte
     * `\xF8`, an empty comment (6.34 … 7.17.2 print `comment:S:0:`, 7.24.5 leaves the key out).
     *
     * @return iterable<string, array{string, list<array<string, string>>}> the fixture, the rows
     */
    public static function measured(): iterable
    {
        yield '6.34 ser3-addresslist' => ['6.34-ser3-addresslist', self::addressList(array_fill(1, 7, null), false)];
        yield '6.49.23 ser3-addresslist' => [
            '6.49.23-ser3-addresslist',
            self::addressList(array_fill(1, 7, 'oct/22/2026 15:42:44'), false),
        ];
        yield '7.1 ser3-addresslist' => [
            '7.1-ser3-addresslist',
            self::addressList(
                array_fill(1, 4, 'oct/22/2026 15:47:19') + array_fill(5, 3, 'oct/22/2026 15:47:20'),
                false,
            ),
        ];
        yield '7.24.5 ser3-addresslist' => [
            '7.24.5-ser3-addresslist',
            self::addressList(
                array_fill(1, 4, '2026-10-22 15:56:00') + array_fill(5, 3, '2026-10-22 15:56:01'),
                true,
            ),
        ];
        $queue = [
            'bucket-size' => '0.1/0.1',
            'burst-limit' => '0/0',
            'burst-threshold' => '0/0',
            'burst-time' => '0s/0s',
            'limit-at' => '0/0',
            'max-limit' => '0/0',
            'packet-marks' => '',
            'parent' => 'none',
            'priority' => '8/8',
            'queue' => 'default-small/default-small',
        ];
        // a list of two elements and of one, an empty list, an empty comment left out
        yield '6.49.23 ser3-queue' => ['6.49.23-ser3-queue', [
            ['.id' => '*1', 'name' => 'qs1', 'target' => '10.0.0.0/24,10.0.1.0/24'] + $queue,
            ['.id' => '*2', 'comment' => 'one element', 'name' => 'qs2', 'target' => '10.0.2.0/24'] + $queue,
        ]];
        yield '6.49.23 ser3-script' => ['6.49.23-ser3-script', [[
            '.id' => '*1',
            'dont-require-permissions' => 'false',
            'name' => 'ml',
            'owner' => 'admin',
            'policy' => 'ftp,reboot,read,write,policy,test,password,sniff,sensitive,romon',
            'run-count' => '0',
            'source' => "a\nb\n  c",
        ]]];
        yield '6.49.23 ser3-service' => ['6.49.23-ser3-service', [
            ['.id' => '*0', 'address' => '', 'name' => 'telnet', 'port' => '23'],
            ['.id' => '*1', 'address' => '', 'name' => 'ftp', 'port' => '21'],
            ['.id' => '*2', 'address' => '', 'name' => 'www', 'port' => '80'],
            ['.id' => '*4', 'address' => '', 'name' => 'ssh', 'port' => '22'],
            ['.id' => '*6', 'address' => '', 'certificate' => 'none', 'name' => 'www-ssl', 'port' => '443'],
            ['.id' => '*7', 'address' => '', 'name' => 'api', 'port' => '8728'],
            ['.id' => '*8', 'address' => '', 'name' => 'winbox', 'port' => '8291'],
            ['.id' => '*9', 'address' => '', 'certificate' => 'test', 'name' => 'api-ssl', 'port' => '8729'],
        ]];
        yield '6.49.23 ser3-route' => ['6.49.23-ser3-route', [
            ['.id' => '*30000001', 'distance' => '1', 'dst-address' => '0.0.0.0/0', 'gateway' => '10.0.2.2'],
            [
                '.id' => '*42069573',
                'distance' => '0',
                'dst-address' => '10.0.2.0/24',
                'gateway' => 'ether1',
                'pref-src' => '10.0.2.15',
            ],
            [
                '.id' => '*42069585',
                'distance' => '0',
                'dst-address' => '192.168.77.0/24',
                'gateway' => 'ether1',
                'pref-src' => '192.168.77.1',
            ],
        ]];
        // O10: singleton menus, one row without "--"
        yield '6.49.23 ser3-resource' => ['6.49.23-ser3-resource', [[
            'architecture-name' => 'x86_64',
            'board-name' => 'CHR',
            'build-time' => 'Oct/07/2026 16:05:16',
            'cpu' => 'QEMU',
            'cpu-count' => '2',
            'cpu-frequency' => '1796',
            'cpu-load' => '42',
            'free-hdd-space' => '33546240',
            'free-memory' => '213782528',
            'platform' => 'MikroTik',
            'total-hdd-space' => '66588672',
            'total-memory' => '234881024',
            'uptime' => '00:01:09',
            'version' => '6.49.23 (stable)',
            'write-sect-since-reboot' => '664',
            'write-sect-total' => '665',
        ]]];
        yield '6.49.23 ser3-identity' => ['6.49.23-ser3-identity', [['name' => 'MikroTik']]];
        $dns = [
            'allow-remote-requests' => 'false',
            'cache-max-ttl' => '1w00:00:00',
            'cache-size' => '2048',
            'dynamic-servers' => '10.0.2.3',
            'max-concurrent-queries' => '100',
            'max-concurrent-tcp-sessions' => '20',
            'max-udp-packet-size' => '4096',
            'query-server-timeout' => '00:00:02',
            'query-total-timeout' => '00:00:10',
            'use-doh-server' => '',
            'verify-doh-cert' => 'false',
        ];
        yield '6.49.23 ser3-dns' => ['6.49.23-ser3-dns', [['cache-used' => '24', 'servers' => ''] + $dns]];
        // the element types ip and ip6 (D5, only 7.24.5 prints them; 6.x prints str)
        yield '7.24.5 d5-ser3-dns' => ['7.24.5-d5-ser3-dns', [[
            'address-list-extra-time' => '00:00:00',
            'cache-used' => '38',
            'doh-max-concurrent-queries' => '50',
            'doh-max-server-connections' => '5',
            'doh-timeout' => '00:00:05',
            'mdns-repeat-ifaces' => '',
            'servers' => '2001:db8::1,10.0.0.1',
            'vrf' => 'main',
        ] + $dns]];
        yield '6.49.23 ser3-empty' => ['6.49.23-ser3-empty', []];
    }

    /**
     * @param list<array<string, string>> $rows
     */
    #[DataProvider('measured')]
    public function testOutputOfTheRouterBecomesTheRowsOfTheApi(string $fixture, array $rows): void
    {
        self::assertSame(self::sorted($rows), self::sorted(TextParser::parse(ConsoleFixture::load($fixture))));
    }

    /** O13, the output side: the bytes of the values are decoded from the charset of the device. */
    public function testValuesAreConvertedFromTheCharsetOfTheDevice(): void
    {
        $rows = TextParser::parse(ConsoleFixture::load('6.49.23-ser3-addresslist'), 'windows-1250');

        self::assertSame(
            ['.id' => '*7', 'address' => '10.0.0.7', 'comment' => 'Př', 'creation-time' => 'oct/22/2026 15:42:44',
                'list' => 'p2'],
            $rows[6],
        );
    }

    /**
     * O11: the console printed its own error instead of the rows or after some of them, and no END.
     *
     * @return iterable<string, array{string}>
     */
    public static function consoleErrors(): iterable
    {
        yield '6.49.23 ser3-err-unwrapped' => [ConsoleFixture::load('6.49.23-ser3-err-unwrapped')];
        yield '6.49.23 ser3-err-after-rows' => [ConsoleFixture::load('6.49.23-ser3-err-after-rows')];
        // the rows of ser3-err-after-rows, then the error text with a colon of e-missing (c-out-6.49.23-console.txt)
        yield 'rows, then an error with a colon' => [
            self::before(ConsoleFixture::load('6.49.23-ser3-err-after-rows'), 'bad command')
                . "Script Error: missing value(s) of argument(s) interface\n",
        ];
    }

    #[DataProvider('consoleErrors')]
    public function testErrorOfTheConsoleIsATrapWithItsTextOnly(string $output): void
    {
        try {
            TextParser::parse($output);
            self::fail('TrapException expected');
        } catch (TrapException $e) {
            // the reason only: the dropped rows are not part of it
            self::assertMatchesRegularExpression('/^(bad command name|Script Error)/', $e->getMessage());
        }
    }

    /**
     * O11 and plan §3.1: an output the ser3 line cannot print. No router printed one, so each row is a real block
     * (ser3-identity, ser3-err-after-rows, 6.49.23) with one edit.
     *
     * @return iterable<string, array{string}>
     */
    public static function broken(): iterable
    {
        $identity = ConsoleFixture::load('6.49.23-ser3-identity');
        $row = self::before(ConsoleFixture::load('6.49.23-ser3-err-after-rows'), 'bad command');
        yield 'nothing: the line did not run' => [''];
        yield 'END is there, the grammar is not' => ["garbage\r\nEND\r\n"];
        yield 'a value runs past the output' => [substr($identity, 0, 14)];
        // a length that lies by two bytes, so that what follows would still parse: name=MikroT without the check
        yield 'a value is not followed by CRLF' => [str_replace([':8:', "\r\nEND"], [':6:', 'END'], $identity)];
        yield 'an element of type array' => ["packet-marks:A:1\r\nE:array:0:\r\n" . $identity];
        yield 'bytes after END' => [$identity . 'x'];
        yield 'a field with an invalid key, no END' => [
            str_replace('name:', 'na me:', self::before($identity, 'END')),
        ];
        yield 'a list row without --' => [$row . str_replace("--\r\n", '', $row) . "END\r\n"];
        yield 'a line in the middle of a row' => [str_replace("--\r\n", '', $row) . "bad command name x\n"];
        yield 'rows, then no END' => [$row];
    }

    #[DataProvider('broken')]
    public function testOutputTheLineCannotPrintIsAProtocolError(string $output): void
    {
        $this->expectException(ProtocolException::class);

        TextParser::parse($output);
    }

    /** The output up to the needle (the test fails when the fixture lacks it). */
    private static function before(string $output, string $needle): string
    {
        $at = strpos($output, $needle);
        if ($at === false) {
            throw new \LogicException('The fixture lacks ' . $needle);
        }

        return substr($output, 0, $at);
    }

    /**
     * @param array<int, ?string> $created the creation-time of *1 … *7 (6.34 has none)
     * @return list<array<string, string>>
     */
    private static function addressList(array $created, bool $flags): array
    {
        $comments = [
            1 => 'plain',
            2 => 'a;b=c "q" \\ $x ? end',
            3 => null,
            4 => 'Příliš žluťoučký',
            5 => "l1\nl2\r\nl3:4;5=6\r\n--\r\nEND",
            6 => 'dis',
            7 => "P\xF8",
        ];
        $rows = [];
        foreach ($comments as $n => $comment) {
            $row = ['.id' => '*' . $n, 'address' => '10.0.0.' . $n, 'list' => 'p2'];
            if ($comment !== null) {
                $row['comment'] = $comment;
            }
            if ($created[$n] !== null) {
                $row['creation-time'] = $created[$n];
            }
            if ($flags) {
                $row['disabled'] = $n === 6 ? 'true' : 'false';
                $row['dynamic'] = 'false';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<array<string, string>> $rows
     * @return list<array<string, string>>
     */
    private static function sorted(array $rows): array
    {
        foreach ($rows as &$row) {
            ksort($row);
        }

        return $rows;
    }
}
