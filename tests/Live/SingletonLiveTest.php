<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * Menus without items (/system/identity, /system/resource ...): the console prints one object instead of a list and
 * rejects a proplist, the API gives one row either way. The public API (Menu::print, Client::version) must work over
 * both transports.
 */
#[Group('live')]
#[Medium]
final class SingletonLiveTest extends LiveTestCase
{
    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function proplists(): iterable
    {
        yield 'no proplist' => [[]];
        yield 'a column' => [['name']];
    }

    /**
     * @param list<string> $proplist
     */
    #[DataProvider('proplists')]
    public function testPrintOfASingletonMenuIsOneRowLikeTheApi(array $proplist): void
    {
        $router = $this->router();
        $byApi = $router->rows(
            '/system/identity/print',
            $proplist === [] ? [] : ['=.proplist=' . implode(',', $proplist)],
        );
        $router->close();

        $rows = $this->client()->menu('/system/identity')->print(null, $proplist);

        self::assertCount(1, $byApi);
        self::assertSame($byApi, $rows);
    }

    public function testVersionOfTheRouter(): void
    {
        $version = $this->client()->version();

        self::assertNotNull($version);
        self::assertTrue(str_starts_with($version, $this->env->chrVersion), $version);
    }
}
