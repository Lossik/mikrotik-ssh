<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests;

use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\ProtocolException;
use Lossik\DeviceCommunication\Query;
use Lossik\DeviceCommunication\TrapException;
use Lossik\DeviceCommunication\UnsupportedOperationException;
use Lossik\Mikrotik\Ssh\CliCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CliCompilerTest extends TestCase
{
    private const MARKER = 'M';

    private const PANGRAM = 'Příliš žluťoučký kůň';

    /** The pangram in windows-1250, the charset 2.x wrote. */
    private const PANGRAM_CP1250 = "P\xF8\xEDli\x9A \x9Elu\x9Dou\xE8k\xFD k\xF9\xF2";

    /** What compile() makes of it: converted first, then every byte >= 0x80 as \HH. */
    private const PANGRAM_CP1250_QUOTED = '"P\\F8\\EDli\\9A \\9Elu\\9Dou\\E8k\\FD k\\F9\\F2"';

    /**
     * S1
     *
     * @return iterable<string, array{string, string}>
     */
    public static function values(): iterable
    {
        yield 'space' => ['a b', '"a b"'];
        yield 'double quotes' => ['say "hi"', '"say \\"hi\\""'];
        yield 'backslash' => ['c:\\x', '"c:\\\\x"'];
        yield 'dollar' => ['$var', '"\\$var"'];
        yield 'question mark' => ['a?b', '"a\\?b"'];
        yield 'line break' => ["l1\nl2", '"l1\\nl2"'];
        yield 'carriage return' => ["l1\r\nl2", '"l1\\r\\nl2"'];
        yield 'empty' => ['', '""'];
        // the console silently drops these bytes when they are typed into a string as they are (live: L7)
        yield 'UTF-8 text' => ['Příliš', '"P\\C5\\99\\C3\\ADli\\C5\\A1"'];
        yield 'tab' => ["a\tb", '"a\\09b"'];
        yield 'control character and delete' => ["\x01\x7F", '"\\01\\7F"'];
    }

    #[DataProvider('values')]
    public function testValueIsQuotedAsARouterOsString(string $value, string $quoted): void
    {
        self::assertSame($quoted, CliCompiler::quote($value));
    }

    /**
     * The line shape per verb. The wrapper is the same in each row.
     *
     * @return iterable<string, array{Command, string}>
     */
    public static function lines(): iterable
    {
        yield 'print' => [
            new Command('/ip/address', 'print'),
            ':put [:serialize to=json options=json.no-string-conversion [/ip/address/print as-value]]',
        ];
        // a singleton menu (/system/resource ...) rejects the proplist: the same line prints without it
        yield 'print with a spaced menu, proplist and query' => [
            new Command('ip address', 'print', [], Query::where('interface', 'ether1'), ['.id', 'address']),
            ':local r; :onerror p in={ :set r [/ip/address/print as-value proplist=address where interface="ether1"] }'
                . ' do={ :if ($p ~ "^bad parameter proplist") do={ :set r [/ip/address/print as-value'
                . ' where interface="ether1"] } else={ :error $p } }; '
                . ':put [:serialize to=json options=json.no-string-conversion $r]',
        ];
        // the console takes no .id in a proplist (live: L12; Menu::update and delete ask for exactly that)
        yield 'print of the .id only asks for no column' => [
            new Command('/ip/address', 'print', [], null, ['.id']),
            ':put [:serialize to=json options=json.no-string-conversion [/ip/address/print as-value]]',
        ];
        yield 'add' => [
            new Command('/ip/address', 'add', ['address' => '10.0.0.1/24', 'comment' => 'a b']),
            ':put [/ip/address/add address="10.0.0.1/24" comment="a b"]',
        ];
        yield 'set several items' => [
            new Command('/ip/address', 'set', ['.id' => '*1,*2', 'comment' => 'x']),
            '/ip/address/set numbers=*1,*2 comment="x"',
        ];
        yield 'remove' => [
            new Command('/ip/address', 'remove', ['.id' => '*A']),
            '/ip/address/remove numbers=*A',
        ];
        yield 'another verb, item given by name' => [
            new Command('/interface', 'disable', ['.id' => 'ether1']),
            '/interface/disable numbers="ether1"',
        ];
        yield 'a value cannot end the command' => [
            new Command('/ip/address', 'set', ['.id' => '*1', 'comment' => '"; /system reset; "']),
            '/ip/address/set numbers=*1 comment="\\"; /system reset; \\""',
        ];
    }

    #[DataProvider('lines')]
    public function testCommandBecomesOneWrappedLine(Command $command, string $line): void
    {
        self::assertSame(
            ':onerror e in={ ' . $line . ' } do={ :put ("M " . $e) }',
            CliCompiler::compile($command, self::MARKER),
        );
    }

    /**
     * @return iterable<string, array{Query, string}>
     */
    public static function queries(): iterable
    {
        yield 'equals' => [Query::where('interface', 'ether1'), 'interface="ether1"'];
        yield 'less' => [Query::less('rx-byte', '100'), 'rx-byte<"100"'];
        yield 'greater' => [Query::greater('rx-byte', '100'), 'rx-byte>"100"'];
        yield 'has' => [Query::has('comment'), 'comment'];
        yield 'has not' => [Query::hasNot('comment'), '!comment'];
        yield 'and with a nested or' => [
            Query::and(Query::where('a', '1'), Query::or(Query::where('b', '2'), Query::where('c', '3'))),
            'a="1" and (b="2" or c="3")',
        ];
        yield 'or with a nested and' => [
            Query::or(Query::and(Query::where('a', '1'), Query::where('b', '2')), Query::where('c', '3')),
            '(a="1" and b="2") or c="3"',
        ];
        // a boolean attribute and a string that looks like one (live: L12, the API's own answers)
        yield 'yes is unquoted' => [Query::where('disabled', 'yes'), 'disabled=yes'];
        yield 'no is unquoted' => [Query::where('comment', 'no'), 'comment=no'];
        yield 'true is a boolean' => [Query::where('disabled', 'true'), 'disabled=[:tobool "true"]'];
        yield 'false is a boolean' => [Query::where('dynamic', 'false'), 'dynamic=[:tobool "false"]'];
        yield 'another spelling stays a string' => [Query::where('comment', 'Yes'), 'comment="Yes"'];
        yield 'a boolean word in a comparison stays a string' => [Query::less('rx-byte', 'yes'), 'rx-byte<"yes"'];
        yield 'not of has' => [Query::not(Query::has('dynamic')), '!(dynamic)'];
        yield 'not of and' => [
            Query::not(Query::and(Query::where('a', '1'), Query::where('b', '2'))),
            '!(a="1" and b="2")',
        ];
    }

    #[DataProvider('queries')]
    public function testQueryBecomesAWhereCondition(Query $query, string $condition): void
    {
        $line = CliCompiler::compile(new Command('/ip/address', 'print', [], $query), self::MARKER);

        self::assertStringContainsString(' as-value where ' . $condition . ']]', $line);
    }

    /**
     * @return iterable<string, array{Command}>
     */
    public static function inexpressible(): iterable
    {
        yield 'query key with a space' => [new Command('/ip/address', 'print', [], Query::where('a b', '1'))];
        yield 'query key in a nested condition' => [
            new Command('/ip/address', 'print', [], Query::not(Query::has('a;b'))),
        ];
        yield 'attribute name with =' => [new Command('/ip/address', 'add', ['a=b' => '1'])];
        yield 'proplist entry with a space' => [new Command('/ip/address', 'print', [], null, ['a b'])];
        yield 'verb that starts another command' => [new Command('/ip/address', 'print; /system/reset')];
        yield 'menu that starts another command' => [new Command('/ip/address; /system/reset', 'print')];
        yield 'print with attributes' => [new Command('/ip/address', 'print', ['from' => '*1'])];
    }

    #[DataProvider('inexpressible')]
    public function testWhatTheLineCannotExpressIsRefusedBeforeAnythingIsSent(Command $command): void
    {
        $this->expectException(UnsupportedOperationException::class);

        CliCompiler::compile($command, self::MARKER);
    }

    /**
     * S2: JSON of `:serialize to=json options=json.no-string-conversion`. The first two rows are the examples of the
     * RouterOS documentation (`{"number":1234,"string":1234.000000}` is what the same value gives without the
     * option); the others are what RouterOS 7.24.5 printed (live probe of F5b, the line end is the console's `\r\n`).
     *
     * @return iterable<string, array{string, list<array<string, string>>}>
     */
    public static function serialized(): iterable
    {
        yield 'documented, numbers as floats' => [
            '[{"number":1234,"string":1234.000000}]',
            [['number' => '1234', 'string' => '1234']],
        ];
        yield 'documented, strings kept' => [
            '[{"number":1234,"string":"1234"}]',
            [['number' => '1234', 'string' => '1234']],
        ];
        yield 'measured: /ip/address, booleans' => [
            '[{".id":"*2","address":"10.97.0.1/24","comment":"lossik-live-test","disabled":false,"dynamic":false,'
                . '"interface":"ether1","invalid":false,"network":"10.97.0.0","slave":false,"vrf":"main"}]' . "\r\n",
            [[
                '.id' => '*2',
                'address' => '10.97.0.1/24',
                'comment' => 'lossik-live-test',
                'disabled' => 'false',
                'dynamic' => 'false',
                'interface' => 'ether1',
                'invalid' => 'false',
                'network' => '10.97.0.0',
                'slave' => 'false',
                'vrf' => 'main',
            ]],
        ];
        yield 'measured: /interface, integers' => [
            '[{".id":"*2","actual-mtu":1500,"disabled":false,"mac-address":"52:54:00:12:34:56","name":"ether1",'
                . '"running":true,"type":"ether"},{".id":"*1","actual-mtu":65536,"disabled":false,'
                . '"mac-address":"00:00:00:00:00:00","name":"lo","running":true,"type":"loopback"}]' . "\r\n",
            [
                [
                    '.id' => '*2',
                    'actual-mtu' => '1500',
                    'disabled' => 'false',
                    'mac-address' => '52:54:00:12:34:56',
                    'name' => 'ether1',
                    'running' => 'true',
                    'type' => 'ether',
                ],
                [
                    '.id' => '*1',
                    'actual-mtu' => '65536',
                    'disabled' => 'false',
                    'mac-address' => '00:00:00:00:00:00',
                    'name' => 'lo',
                    'running' => 'true',
                    'type' => 'loopback',
                ],
            ],
        ];
        yield 'measured: strings that look like numbers' => [
            '[{"comment":"007"},{"comment":"1e3"},{"comment":"0x10"},{"comment":" 12 "},{"comment":"1.50"},'
                . '{"comment":"12345678901234567890"}]' . "\r\n",
            [
                ['comment' => '007'],
                ['comment' => '1e3'],
                ['comment' => '0x10'],
                ['comment' => ' 12 '],
                ['comment' => '1.50'],
                ['comment' => '12345678901234567890'],
            ],
        ];
        yield 'measured: quote, backslash, dollar, question mark, semicolon, equals sign' => [
            '[{".id":"*A","comment":"a=b;c=d \"q\" \\\\ $x ?"}]' . "\r\n",
            [['.id' => '*A', 'comment' => 'a=b;c=d "q" \\ $x ?']],
        ];
        yield 'measured: UTF-8 is not escaped' => [
            '[{"address":"10.97.1.51","comment":"Příliš žluťoučký kůň"}]' . "\r\n",
            [['address' => '10.97.1.51', 'comment' => 'Příliš žluťoučký kůň']],
        ];
        // the API joins a list with commas, an empty one is "" (live: MenuLiveTest, /queue/simple and the leases)
        yield 'measured: lists' => [
            '[{"target":["10.96.0.1/32","10.96.0.9/32"],"packet-marks":["pm1"],"dhcp-option":[],"limit-at":[1,2]}]',
            [[
                'target' => '10.96.0.1/32,10.96.0.9/32',
                'packet-marks' => 'pm1',
                'dhcp-option' => '',
                'limit-at' => '1,2',
            ]],
        ];
        yield 'a list of lists is kept as JSON' => ['[{"a":[["x"],"y"]}]', [['a' => '[["x"],"y"]']]];
        // the API leaves an empty comment out, other empty values it keeps (live: caller-id of /ppp/secret)
        yield 'RouterOS 7.17.2: an empty comment' => [
            '[{".id":"*1","address":"10.0.0.1/24","comment":""}]' . "\r\n",
            [['.id' => '*1', 'address' => '10.0.0.1/24']],
        ];
        yield 'an empty value other than comment is kept' => [
            '[{"comment":"x","caller-id":""}]',
            [['comment' => 'x', 'caller-id' => '']],
        ];
        // a singleton menu prints an object (measured: /system/clock on 7.24.5)
        yield 'measured: a singleton menu, one object' => [
            '{"date":"2026-10-22","dst-active":false,"gmt-offset":0,"time":"13:07:34","time-zone-name":"manual"}'
                . "\r\n",
            [[
                'date' => '2026-10-22',
                'dst-active' => 'false',
                'gmt-offset' => '0',
                'time' => '13:07:34',
                'time-zone-name' => 'manual',
            ]],
        ];
        yield 'large counter' => ['[{"rx-byte":12345678901234567890}]', [['rx-byte' => '12345678901234567890']]];
        yield 'several rows' => ['[{"a":1},{"a":2}]', [['a' => '1'], ['a' => '2']]];
        yield 'measured: empty result' => ["[]\r\n", []];
        yield 'nothing printed' => ['', []];
    }

    /**
     * @param list<array<string, string>> $rows
     */
    #[DataProvider('serialized')]
    public function testPrintOutputBecomesRowsOfStrings(string $json, array $rows): void
    {
        $result = CliCompiler::result(new Command('/ip/address', 'print'), $json, self::MARKER);

        self::assertSame($rows, $result->rows);
        self::assertNull($result->ret);
    }

    /**
     * The console always prints `.id` and `comment`; the API prints the requested columns only.
     *
     * @return iterable<string, array{list<string>, list<array<string, string>>}>
     */
    public static function proplists(): iterable
    {
        yield 'a column' => [['address'], [['address' => '10.0.0.1/24']]];
        yield 'the .id' => [['.id'], [['.id' => '*1']]];
        yield 'both' => [['address', '.id'], [['.id' => '*1', 'address' => '10.0.0.1/24']]];
        yield 'none: all that was printed' => [
            [],
            [['.id' => '*1', 'address' => '10.0.0.1/24', 'comment' => 'c']],
        ];
    }

    /**
     * @param list<string> $proplist
     * @param list<array<string, string>> $rows
     */
    #[DataProvider('proplists')]
    public function testPrintKeepsOnlyTheRequestedColumns(array $proplist, array $rows): void
    {
        $json = '[{".id":"*1","address":"10.0.0.1/24","comment":"c"}]';

        $result = CliCompiler::result(new Command('/ip/address', 'print', [], null, $proplist), $json, self::MARKER);

        self::assertSame($rows, $result->rows);
    }

    public function testAddReturnsTheIdOfTheNewItem(): void
    {
        $result = CliCompiler::result(new Command('/ip/address', 'add'), "*1F\r\n", self::MARKER);

        self::assertSame('*1F', $result->ret);
        self::assertSame([], $result->rows);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'print, marker only' => ['print', "M\r\n"];
        yield 'print, marker after other output' => ['print', "something\r\nM\r\n"];
        yield 'print, a message of the console instead of JSON' => ['print', "syntax error (line 1 column 34)\n"];
        yield 'add, marker' => ['add', "M\r\n"];
        yield 'add, a message instead of an ID' => ['add', "syntax error (line 1 column 22)\n"];
        yield 'set, marker' => ['set', "M\r\n"];
        yield 'set, a message of the console' => ['set', "expected end of command (line 1 column 64)\n"];
    }

    #[DataProvider('refusals')]
    public function testWhatTheCommandRefusedIsATrap(string $verb, string $output): void
    {
        $this->expectException(TrapException::class);

        CliCompiler::result(new Command('/ip/address', $verb), $output, self::MARKER);
    }

    /**
     * @return iterable<string, array{string, string}> the output, the message of the TrapException
     */
    public static function failures(): iterable
    {
        yield 'the marker alone' => ["M\r\n", '/ip/address set failed'];
        yield 'the reason of the console' => [
            "M no such item (4) (/ip/address/set; line 1)\r\n",
            'no such item (4) (/ip/address/set; line 1)',
        ];
    }

    #[DataProvider('failures')]
    public function testTrapSaysWhyTheCommandFailed(string $output, string $message): void
    {
        try {
            CliCompiler::result(new Command('/ip/address', 'set'), $output, self::MARKER);
            self::fail('TrapException expected');
        } catch (TrapException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /**
     * The charset of the device (SshOptions::$charset): values are converted from UTF-8 before they are escaped, so
     * the \HH of the line are the bytes the device stores.
     *
     * @return iterable<string, array{Command, string, string}> the command, the charset, the line (inside the wrapper)
     */
    public static function convertedValues(): iterable
    {
        yield 'value of add' => [
            new Command('/ip/address', 'add', ['comment' => self::PANGRAM]),
            'windows-1250',
            ':put [/ip/address/add comment=' . self::PANGRAM_CP1250_QUOTED . ']',
        ];
        yield 'item given by name' => [
            new Command('/interface', 'set', ['.id' => 'ř']),
            'windows-1250',
            '/interface/set numbers="\\F8"',
        ];
        yield 'value of a condition' => [
            new Command('/ip/address', 'print', [], Query::where('comment', self::PANGRAM)),
            'windows-1250',
            ':put [:serialize to=json options=json.no-string-conversion [/ip/address/print as-value where comment='
                . self::PANGRAM_CP1250_QUOTED . ']]',
        ];
        yield 'value of a comparison' => [
            new Command('/ip/address', 'print', [], Query::greater('comment', 'ř')),
            'windows-1250',
            ':put [:serialize to=json options=json.no-string-conversion '
                . '[/ip/address/print as-value where comment>"\\F8"]]',
        ];
        yield 'UTF-8 in any case is not converted' => [
            new Command('/ip/address', 'add', ['comment' => 'ř']),
            'utf-8',
            ':put [/ip/address/add comment="\\C5\\99"]',
        ];
    }

    #[DataProvider('convertedValues')]
    public function testValuesAreConvertedToTheCharsetOfTheDevice(Command $command, string $charset, string $line): void
    {
        self::assertSame(
            ':onerror e in={ ' . $line . ' } do={ :put ("M " . $e) }',
            CliCompiler::compile($command, self::MARKER, $charset),
        );
    }

    /**
     * @return iterable<string, array{Command}>
     */
    public static function unconvertible(): iterable
    {
        yield 'value of add' => [new Command('/ip/address', 'add', ['comment' => '日本'])];
        yield 'item given by name' => [new Command('/interface', 'set', ['.id' => '日本'])];
        yield 'value of a condition' => [new Command('/ip/address', 'print', [], Query::where('comment', '日本'))];
        yield 'value of a comparison' => [new Command('/ip/address', 'print', [], Query::less('comment', '日本'))];
    }

    #[DataProvider('unconvertible')]
    public function testValueThatTheCharsetCannotHoldIsRefusedBeforeAnythingIsSent(Command $command): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CliCompiler::compile($command, self::MARKER, 'windows-1250');
    }

    public function testOutputIsConvertedFromTheCharsetOfTheDeviceBeforeItIsParsed(): void
    {
        $json = '[{".id":"*1","comment":"' . self::PANGRAM_CP1250 . '"}]';

        $result = CliCompiler::result(new Command('/ip/address', 'print'), $json, self::MARKER, 'windows-1250');

        self::assertSame([['.id' => '*1', 'comment' => self::PANGRAM]], $result->rows);
    }

    public function testReasonOfAFailureIsConvertedFromTheCharsetOfTheDevice(): void
    {
        try {
            $output = "M no such item \xF8\r\n";
            CliCompiler::result(new Command('/ip/address', 'set'), $output, self::MARKER, 'windows-1250');
            self::fail('TrapException expected');
        } catch (TrapException $e) {
            self::assertSame('no such item ř', $e->getMessage());
        }
    }

    public function testOutputThatIsNotInTheCharsetIsAProtocolError(): void
    {
        $this->expectException(ProtocolException::class);

        // 0x81 is not assigned in windows-1250
        $output = "[{\"comment\":\"\x81\"}]";
        CliCompiler::result(new Command('/ip/address', 'print'), $output, self::MARKER, 'windows-1250');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notRows(): iterable
    {
        yield 'a list that holds a scalar' => ['[{"a":"b"},"c"]'];
        yield 'a list of strings' => ['["a","b"]'];
        yield 'a scalar' => ['5'];
    }

    #[DataProvider('notRows')]
    public function testPrintOutputThatIsNotAListOfRowsIsAProtocolError(string $json): void
    {
        $this->expectException(ProtocolException::class);

        CliCompiler::result(new Command('/ip/address', 'print'), $json, self::MARKER);
    }
}
