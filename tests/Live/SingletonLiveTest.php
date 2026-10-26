<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * Menus without items (/system/identity, /system/resource, /ip/dns): the console prints one object (JSON path) or
 * string-keyed fields without `--` (ser3 text path) instead of a list; the public API (Menu::print, Client::version)
 * returns one row like the API transport, and SshTransport::consolePath() matches the version table (OL1, OL2, OL10).
 */
#[Group('live')]
#[Medium]
final class SingletonLiveTest extends LiveTestCase
{
    /**
     * The configuration a singleton menu prints in another form on the console than over the API: the times of
     * /ip/dns, e.g. `cache-max-ttl` `1w00:00:00` on the console (`fd-ssh-probe-7.16.2.txt`, dns-plain), `1w` over the
     * API. Not counters, they do not tick: a known difference, like MenuLiveTest::DIFFERENT.
     *
     * @var array<string, list<string>>
     */
    private const DIFFERENT = [
        '/ip/dns' => [
            'address-list-extra-time',
            'cache-max-ttl',
            'doh-timeout',
            'query-server-timeout',
            'query-total-timeout',
        ],
    ];

    /**
     * OL10: singleton menus with and without a proplist; the counters that tick between the API call and the SSH call
     * are excluded from the comparison, and so are the known differences (DIFFERENT).
     *
     * @return iterable<string, array{string, list<string>, list<string>}> the menu, the proplist, the counters
     */
    public static function singletons(): iterable
    {
        $resourceCounters = [
            'cpu-load',
            'free-hdd-space',
            'free-memory',
            'uptime',
            'write-sect-since-reboot',
            'write-sect-total',
        ];
        yield '/system/identity, no proplist' => ['/system/identity', [], []];
        yield '/system/identity, with proplist' => ['/system/identity', ['name'], []];
        yield '/system/resource, no proplist' => ['/system/resource', [], $resourceCounters];
        yield '/system/resource, with proplist' => [
            '/system/resource',
            ['architecture-name', 'board-name', 'version'],
            [],
        ];
        yield '/ip/dns, no proplist' => ['/ip/dns', [], ['cache-used']];
        yield '/ip/dns, with proplist' => [
            '/ip/dns',
            ['allow-remote-requests', 'dynamic-servers', 'servers'],
            [],
        ];
    }

    /**
     * @param list<string> $proplist
     * @param list<string> $counters
     */
    #[DataProvider('singletons')]
    public function testPrintOfASingletonMenuIsOneRowLikeTheApi(
        string $menu,
        array $proplist,
        array $counters,
    ): void {
        $router = $this->router();
        $byApi = $router->rows(
            $menu . '/print',
            $proplist === [] ? [] : ['=.proplist=' . implode(',', $proplist)],
        );
        $router->close();

        $rows = $this->client()->menu($menu)->print(null, $proplist);

        self::assertCount(1, $byApi);
        self::assertCount(1, $rows);
        $ignore = array_flip([...$counters, ...self::DIFFERENT[$menu] ?? []]);
        $expected = array_diff_key($byApi[0], $ignore);
        $actual = array_diff_key($rows[0], $ignore);
        // the text path reads the fields in the order the console prints them, not the API's; the order is no part
        // of a row
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    /**
     * OL1: Client::version() over SSH starts with CHR_VERSION.
     */
    public function testVersionOfTheRouter(): void
    {
        $version = $this->client()->version();

        self::assertNotNull($version);
        self::assertTrue(str_starts_with($version, $this->env->chrVersion), $version);
    }

    /**
     * OL2: SshTransport::consolePath() is null before the first command and equals MIKROTIK_LIVE_SSH_PATH after it.
     */
    public function testConsolePathMatchesTheVersionTable(): void
    {
        $transport = $this->transport(options: $this->env->options());
        self::assertNull($transport->consolePath());

        (new Client($transport))->menu('/system/identity')->print();

        self::assertSame($this->env->sshPath, $transport->consolePath());
    }
}
