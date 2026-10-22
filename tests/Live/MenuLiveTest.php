<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * The menus the first consumer uses, through the console (plan L14, SSH part; the API part is in mikrotik-api):
 * add, print, update and delete on each of them, `print` compared with what the API gives for the same item.
 */
#[Group('live')]
#[Medium]
final class MenuLiveTest extends LiveTestCase
{
    /**
     * The attributes of an item that stay different from the API's, whatever the library does (measured on 7.24.5 and
     * 7.23.8; the README lists them). Everything else of a row must equal the API's.
     *
     *  - another unit: the console prints `bucket-size` as `100` and `burst-time` as `00:00:00` (API `0.1`, `0s`),
     *    `life-time` of an IPv6 binding as a date (`1970-01-08 00:00:00`, API `1w`);
     *  - not printed at all, with or without a proplist: the counters (`bytes`, `packets`, `rate` ...) and the
     *    `password` of /ppp/secret.
     *
     * What the console prints only when asked for in the proplist (`actual-interface` of /ip/address, `iaid` and
     * `last-seen` of an IPv6 binding, `routes` of /ppp/secret, the `agent-*` of a lease ...) is not here: the test asks
     * for every attribute the API returned, as a caller who names the columns does.
     *
     * @var array<string, list<string>>
     */
    private const DIFFERENT = [
        '/queue/tree' => [
            'bucket-size', 'burst-time',
            'bytes', 'dropped', 'packet-rate', 'packets', 'queued-bytes', 'queued-packets', 'rate',
        ],
        '/queue/simple' => [
            'bytes', 'dropped', 'packet-rate', 'packets', 'queued-bytes', 'queued-packets', 'rate',
            'total-bytes', 'total-dropped', 'total-packet-rate', 'total-packets', 'total-queued-bytes',
            'total-queued-packets', 'total-rate',
        ],
        '/ip/firewall/nat' => ['bytes', 'packets'],
        '/ip/firewall/filter' => ['bytes', 'packets'],
        '/ip/firewall/mangle' => ['bytes', 'packets'],
        '/ppp/secret' => ['password'],
        '/ipv6/dhcp-server/binding' => ['life-time'],
    ];

    /**
     * The attributes `add` needs on each menu (RouterOS's own "missing =parent=" and the like, as in the API part of
     * L14), with a list of two items wherever the menu has a list attribute.
     *
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function menus(): iterable
    {
        yield '/queue/tree' => [
            '/queue/tree',
            ['name' => 'lossik-live-q', 'parent' => 'global', 'packet-mark' => 'pm1,pm2'],
        ];
        yield '/queue/simple' => [
            '/queue/simple',
            ['name' => 'lossik-live-qs', 'target' => '10.96.0.1/32,10.96.0.9/32', 'packet-marks' => 'pm1,pm2'],
        ];
        yield '/ip/firewall/nat' => ['/ip/firewall/nat', ['chain' => 'srcnat', 'action' => 'masquerade']];
        yield '/ip/firewall/filter' => ['/ip/firewall/filter', ['chain' => 'forward', 'action' => 'accept']];
        yield '/ip/firewall/mangle' => [
            '/ip/firewall/mangle',
            ['chain' => 'prerouting', 'action' => 'mark-packet', 'new-packet-mark' => 'lossik-live'],
        ];
        yield '/ip/firewall/address-list' => [
            '/ip/firewall/address-list',
            ['list' => 'lossik-live-menu', 'address' => '10.96.0.2'],
        ];
        yield '/ip/arp' => ['/ip/arp', ['address' => '10.96.0.3', 'interface' => 'ether1']];
        yield '/ip/address' => ['/ip/address', ['address' => '10.96.1.1/24', 'interface' => 'ether1']];
        yield '/ppp/secret' => ['/ppp/secret', ['name' => 'lossik-live-u']];
        yield '/ip/dhcp-server/lease' => [
            '/ip/dhcp-server/lease',
            ['address' => '10.96.0.4', 'mac-address' => '02:00:00:00:00:02', 'address-lists' => 'lossik-1,lossik-2'],
        ];
        yield '/ipv6/dhcp-server/binding' => [
            '/ipv6/dhcp-server/binding',
            ['address' => '2001:db8::1/128', 'duid' => '0x0001', 'iaid' => '1'],
        ];
        yield '/ipv6/firewall/address-list' => [
            '/ipv6/firewall/address-list',
            ['list' => 'lossik-live-menu', 'address' => '2001:db8::2/128'],
        ];
    }

    /**
     * @param array<string, string> $attributes
     */
    #[DataProvider('menus')]
    public function testAddPrintUpdateDeleteOnTheMenu(string $path, array $attributes): void
    {
        $menu = $this->client()->menu($path);
        $mine = Query::where('comment', self::TAG);
        $router = $this->router();

        $id = $menu->add($attributes + ['comment' => self::TAG]);
        $byApi = $router->rows($path . '/print', ['?comment=' . self::TAG]);
        $named = $menu->print($mine, array_keys($byApi[0] ?? []));
        $unnamed = $menu->print($mine);
        $updated = $menu->update($mine, ['disabled' => 'yes']);
        $disabledByApi = $router->rows($path . '/print', ['?comment=' . self::TAG, '=.proplist=disabled']);
        $deleted = $menu->delete($mine);
        $leftByApi = $router->rows($path . '/print', ['?comment=' . self::TAG]);
        $router->close();

        self::assertMatchesRegularExpression('/^\*[0-9A-F]+$/', (string) $id);
        self::assertCount(1, $byApi);
        self::assertSame($id, $byApi[0]['.id']);
        // the columns the caller names come out as the API gives them, except what the console cannot print that way
        $known = array_flip(self::DIFFERENT[$path] ?? []);
        $expected = self::sorted(array_diff_key($byApi[0], $known));
        self::assertCount(1, $named);
        self::assertSame($expected, self::sorted(array_diff_key($named[0], $known)));
        // without a proplist the console prints fewer attributes, but those it prints are the API's
        self::assertCount(1, $unnamed);
        $plain = self::sorted(array_diff_key($unnamed[0], $known));
        self::assertSame(self::sorted(array_intersect_key($expected, $plain)), $plain);
        self::assertSame(1, $updated);
        self::assertSame(['true'], array_column($disabledByApi, 'disabled'));
        self::assertSame(1, $deleted);
        self::assertSame([], $leftByApi);
    }

    public function testDhcpServerCanBePrinted(): void
    {
        // the CHR has no DHCP server and the live tests add none
        self::assertSame([], $this->client()->menu('/ip/dhcp-server')->print());
    }

    /**
     * @param array<string, string> $row
     * @return array<string, string>
     */
    private static function sorted(array $row): array
    {
        ksort($row);

        return $row;
    }
}
