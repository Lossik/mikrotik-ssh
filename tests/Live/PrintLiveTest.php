<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * `print` through `:serialize to=json` and the ser3 text path against a real RouterOS (plan L12, S2, U13, FAQ-5a,
 * OL11, OL12): the rows are the ones the API gives, every value a string. Many rows (OL9): PrintManyRowsLiveTest.
 */
#[Group('live')]
#[Medium]
final class PrintLiveTest extends LiveTestCase
{
    private const MENU = '/ip/firewall/address-list';

    private const COLUMNS = ['.id', 'list', 'address', 'comment', 'disabled', 'dynamic'];

    public function testRowsAreTheOnesOfTheApiWithEveryValueAString(): void
    {
        $router = $this->router();
        $router->add(self::MENU, ['list' => self::LIST, 'address' => '10.97.0.1', 'comment' => 'plain']);
        // no comment at all: the API leaves the attribute out; so must the console
        $router->add(self::MENU, ['list' => self::LIST, 'address' => '10.97.0.2']);
        $router->add(
            self::MENU,
            ['list' => self::LIST, 'address' => '10.97.0.3', 'comment' => 'x', 'disabled' => 'yes'],
        );
        $expected = self::normalised($router->rows(
            self::MENU . '/print',
            ['?list=' . self::LIST, '=.proplist=' . implode(',', self::COLUMNS)],
        ));
        $router->close();

        $rows = $this->transport()->execute(
            new Command(self::MENU, 'print', [], Query::where('list', self::LIST), self::COLUMNS),
        )->rows;

        self::assertCount(3, $expected);
        self::assertSame($expected, self::normalised($rows));
        self::assertSame('true', $rows[2]['disabled']);
        self::assertArrayNotHasKey('comment', $rows[1]);
    }

    /**
     * The console prints `.id` and `comment` whatever the proplist says; the API prints the requested columns only.
     */
    public function testOnlyTheRequestedColumnsAreReturned(): void
    {
        $router = $this->router();
        $router->add(self::MENU, ['list' => self::LIST, 'address' => '10.97.0.5', 'comment' => 'not requested']);
        $byApi = $router->rows(self::MENU . '/print', ['?list=' . self::LIST, '=.proplist=address']);
        $router->close();

        $rows = $this->client()->menu(self::MENU)->print(Query::where('list', self::LIST), ['address']);

        self::assertSame([['address' => '10.97.0.5']], $byApi);
        self::assertSame($byApi, $rows);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function numberLikeStrings(): iterable
    {
        yield 'leading zeros' => ['007'];
        yield 'exponent' => ['1e3'];
        yield 'hexadecimal' => ['0x10'];
        yield 'decimal with a trailing zero' => ['1.50'];
        yield 'negative' => ['-5'];
        yield 'too long for a float' => ['12345678901234567890'];
        yield 'spaces around' => [' 12 '];
        yield 'true' => ['true'];
    }

    /**
     * OL11 (FAQ-5a): `:serialize` alone turns a string that looks like a number into a number (`007` -> 7.000000);
     * the option json.no-string-conversion keeps the string, as the API does.
     */
    #[DataProvider('numberLikeStrings')]
    #[Group('live-json')]
    public function testStringThatLooksLikeANumberStaysAsItIs(string $comment): void
    {
        $router = $this->router();
        $router->add(self::MENU, ['list' => self::LIST, 'address' => '10.97.0.4', 'comment' => $comment]);
        $router->close();

        $row = $this->client()->menu(self::MENU)->first(Query::where('list', self::LIST));

        self::assertSame($comment, $row['comment'] ?? null);
    }

    /**
     * OL12: text-path specifics (ser3 grammar, plan §2.2, §7.3) - a value containing `--`, `END` and CRLF, a cp1250
     * byte `\xF8` read with `charset: 'windows-1250'`, and a list attribute with one element.
     */
    #[Group('live-text')]
    public function testTextPathReadsDelimitersCp1250ByteAndSingleElementList(): void
    {
        $delimiters = "l1\nl2\r\nl3:4;5=6\r\n--\r\nEND";
        $router = $this->router();
        $router->add(self::MENU, ['list' => self::LIST, 'address' => '10.97.0.10', 'comment' => $delimiters]);
        $router->add(self::MENU, ['list' => self::LIST, 'address' => '10.97.0.11', 'comment' => "P\xF8"]);
        $router->add(
            '/queue/simple',
            ['name' => 'lossik-live-qs1', 'target' => '10.97.0.1/32', 'packet-marks' => 'pm1', 'comment' => self::TAG],
        );
        $delimByApi = $router->rows(
            self::MENU . '/print',
            ['?address=10.97.0.10', '=.proplist=.id,list,address,comment'],
        );
        $queueByApi = $router->rows(
            '/queue/simple/print',
            ['?comment=' . self::TAG, '=.proplist=.id,name,target,packet-marks'],
        );
        $router->close();

        $client = $this->client();
        $delimBySsh = $client->menu(self::MENU)->print(
            Query::where('address', '10.97.0.10'),
            ['.id', 'list', 'address', 'comment'],
        );
        $cp1250 = new Client($this->transport(options: $this->env->options(charset: 'windows-1250')));
        $cp1250Row = $cp1250->menu(self::MENU)->print(Query::where('address', '10.97.0.11'), ['address', 'comment']);
        $queueBySsh = $client->menu('/queue/simple')->print(
            Query::where('comment', self::TAG),
            ['.id', 'name', 'target', 'packet-marks'],
        );

        self::assertSame(self::normalised($delimByApi), self::normalised($delimBySsh));
        self::assertSame($delimiters, $delimBySsh[0]['comment'] ?? null);
        self::assertSame([['address' => '10.97.0.11', 'comment' => 'Př']], $cp1250Row);
        self::assertSame(self::normalised($queueByApi), self::normalised($queueBySsh));
        self::assertSame('10.97.0.1/32', $queueBySsh[0]['target'] ?? null);
        self::assertSame('pm1', $queueBySsh[0]['packet-marks'] ?? null);
    }

    /**
     * With a proplist the line prints through a variable (see CliCompiler); an empty answer must survive that.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function proplists(): iterable
    {
        yield 'no proplist' => [[]];
        yield 'a column' => [['address']];
    }

    /**
     * @param list<string> $proplist
     */
    #[DataProvider('proplists')]
    public function testPrintWithNoMatchIsAnEmptyList(array $proplist): void
    {
        $rows = $this->client()->menu(self::MENU)->print(Query::where('list', 'lossik-live-no-such-list'), $proplist);

        self::assertSame([], $rows);
    }

    /**
     * @param list<array<string, string>> $rows
     * @return list<array<string, string>> the rows with the keys in one order
     */
    private static function normalised(array $rows): array
    {
        foreach ($rows as &$row) {
            ksort($row);
        }

        return $rows;
    }
}
