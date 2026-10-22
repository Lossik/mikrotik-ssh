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
            ':put [:serialize to=json [/ip/address/print as-value]]',
        ];
        yield 'print with a spaced menu, proplist and query' => [
            new Command('ip address', 'print', [], Query::where('interface', 'ether1'), ['.id', 'address']),
            ':put [:serialize to=json [/ip/address/print as-value proplist=.id,address where interface="ether1"]]',
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
            ':do { ' . $line . ' } on-error={ :put "M" }',
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
     * S2: JSON of `:serialize to=json`. The first two rows are the examples of the RouterOS documentation (scripting
     * examples: `{"number":1234,"string":1234.000000}`, and with `options=json.no-string-conversion`
     * `{"number":1234,"string":"1234"}`); the others are print rows in the same notation. The shape of print
     * is confirmed on a router by L12 (F5).
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
        yield 'booleans' => [
            '[{".id":"*1","disabled":false,"dynamic":true}]',
            [['.id' => '*1', 'disabled' => 'false', 'dynamic' => 'true']],
        ];
        yield 'semicolon and equals sign in a value' => [
            '[{".id":"*1","comment":"a=b;c=d"}]',
            [['.id' => '*1', 'comment' => 'a=b;c=d']],
        ];
        yield 'unicode and slashes' => [
            '[{"comment":"Příliš žluťoučký kůň","address":"10.0.0.1\/24"}]',
            [['comment' => 'Příliš žluťoučký kůň', 'address' => '10.0.0.1/24']],
        ];
        yield 'large counter' => ['[{"rx-byte":12345678901234567890}]', [['rx-byte' => '12345678901234567890']]];
        yield 'several rows' => ['[{"a":1},{"a":2}]', [['a' => '1'], ['a' => '2']]];
        yield 'empty result' => ['[]', []];
        yield 'empty result with the line end of the console' => ["[]\r\n", []];
        yield 'rows with the line end of the console' => ["[{\"a\":\"1\"}]\r\n", [['a' => '1']]];
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
        yield 'print, a message of the console instead of JSON' => ['print', "syntax error (line 1 column 9)\r\n"];
        yield 'add, marker' => ['add', "M\r\n"];
        yield 'add, a message instead of an ID' => ['add', "failure: already have such address\r\n"];
        yield 'set, marker' => ['set', "M\r\n"];
        yield 'set, a message of the console' => ['set', "bad command name sett (line 1 column 13)\r\n"];
    }

    #[DataProvider('refusals')]
    public function testWhatTheCommandRefusedIsATrap(string $verb, string $output): void
    {
        $this->expectException(TrapException::class);

        CliCompiler::result(new Command('/ip/address', $verb), $output, self::MARKER);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notRows(): iterable
    {
        yield 'an object' => ['{"a":"b"}'];
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
