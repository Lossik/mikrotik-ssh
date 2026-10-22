<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * Values that 2.x mangled (plan L2, SSH half; A8) and the charset (L7, SSH half; U2, Q-D): what is written through the
 * console comes back identical through the console and through the API, and the other way round.
 */
#[Group('live')]
#[Medium]
final class ValuesLiveTest extends LiveTestCase
{
    private const MENU = '/ip/firewall/address-list';

    private const ADDRESS = '10.98.0.1';

    private const PANGRAM = 'Příliš žluťoučký kůň';

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardValues(): iterable
    {
        yield 'equals signs' => ['a=b=c'];
        yield 'new line' => ["l1\nl2"];
        yield 'carriage return and new line' => ["l1\r\nl2"];
        yield 'leading and trailing spaces' => ['  lead and trail  '];
        yield 'double quote' => ['say "hi"'];
        yield 'dollar' => ['$var'];
        yield 'backslash' => ['c:\\x'];
        yield 'question mark' => ['why?'];
        yield 'semicolon' => ['a;b'];
        yield 'tab' => ["a\tb"];
        yield 'Czech text' => [self::PANGRAM];
        yield 'four-byte UTF-8' => ["smile \u{1F600}"];
    }

    #[DataProvider('awkwardValues')]
    public function testValueWrittenThroughTheConsoleComesBackIdentical(string $value): void
    {
        $menu = $this->client()->menu(self::MENU);

        $menu->add(['list' => self::LIST, 'address' => self::ADDRESS, 'comment' => $value]);
        $throughConsole = $menu->first(Query::where('list', self::LIST));
        $router = $this->router();
        $throughApi = $router->rows(self::MENU . '/print', ['?list=' . self::LIST])[0] ?? [];
        $router->close();

        self::assertSame($value, $throughConsole['comment'] ?? null);
        self::assertSame($value, $throughApi['comment'] ?? null);
    }

    /**
     * U2, the other direction: the API stores the bytes it is given, and the console shows them as UTF-8 text, so the
     * default charset of the console side needs no conversion.
     */
    public function testTextStoredThroughTheApiAsUtf8IsReadAsUtf8ByTheConsole(): void
    {
        $router = $this->router();
        $router->add(self::MENU, ['list' => self::LIST, 'address' => self::ADDRESS, 'comment' => self::PANGRAM]);
        $router->close();

        $row = $this->client()->menu(self::MENU)->first(Query::where('list', self::LIST));

        self::assertSame(self::PANGRAM, $row['comment'] ?? null);
    }
}
