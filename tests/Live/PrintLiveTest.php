<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * `print` through `:serialize to=json` against a real RouterOS (plan L12, S2, U13, FAQ-5a): the rows are the ones
 * the API gives, every value a string.
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
     * FAQ-5a: `:serialize` alone turns a string that looks like a number into a number (`007` -> 7.000000); the
     * option json.no-string-conversion keeps the string, as the API does.
     */
    #[DataProvider('numberLikeStrings')]
    public function testStringThatLooksLikeANumberStaysAsItIs(string $comment): void
    {
        $router = $this->router();
        $router->add(self::MENU, ['list' => self::LIST, 'address' => '10.97.0.4', 'comment' => $comment]);
        $router->close();

        $row = $this->client()->menu(self::MENU)->first(Query::where('list', self::LIST));

        self::assertSame($comment, $row['comment'] ?? null);
    }

    public function testPrintWithNoMatchIsAnEmptyList(): void
    {
        $rows = $this->client()->menu(self::MENU)->print(Query::where('list', 'lossik-live-no-such-list'));

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
