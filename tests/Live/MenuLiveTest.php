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
     * The attributes `add` needs on each menu (RouterOS's own "missing =parent=" and the like, as in the API part of
     * L14), and the attributes whose value the console prints differently from the API (measured on 7.24.5 and
     * 7.23.8): a list is a JSON array (`target`, `packet-mark`, `packet-marks`: the API joins it with commas, an empty
     * one is `""`), `bucket-size` and `burst-time` of /queue/tree are in other units. The console prints fewer
     * attributes than the API (no counters, no `password` of /ppp/secret); `proplist` names them when they are needed.
     *
     * @return iterable<string, array{string, array<string, string>, list<string>}>
     */
    public static function menus(): iterable
    {
        yield '/queue/tree' => [
            '/queue/tree',
            ['name' => 'lossik-live-q', 'parent' => 'global'],
            ['bucket-size', 'burst-time', 'packet-mark'],
        ];
        yield '/queue/simple' => [
            '/queue/simple',
            ['name' => 'lossik-live-qs', 'target' => '10.96.0.1/32'],
            ['packet-marks', 'target'],
        ];
        yield '/ip/firewall/nat' => ['/ip/firewall/nat', ['chain' => 'srcnat', 'action' => 'masquerade'], []];
        yield '/ip/firewall/filter' => ['/ip/firewall/filter', ['chain' => 'forward', 'action' => 'accept'], []];
        yield '/ip/firewall/mangle' => [
            '/ip/firewall/mangle',
            ['chain' => 'prerouting', 'action' => 'mark-packet', 'new-packet-mark' => 'lossik-live'],
            [],
        ];
        yield '/ip/firewall/address-list' => [
            '/ip/firewall/address-list',
            ['list' => 'lossik-live-menu', 'address' => '10.96.0.2'],
            [],
        ];
        yield '/ip/arp' => ['/ip/arp', ['address' => '10.96.0.3', 'interface' => 'ether1'], []];
        yield '/ip/address' => ['/ip/address', ['address' => '10.96.1.1/24', 'interface' => 'ether1'], []];
        yield '/ppp/secret' => ['/ppp/secret', ['name' => 'lossik-live-u'], []];
        yield '/ip/dhcp-server/lease' => [
            '/ip/dhcp-server/lease',
            ['address' => '10.96.0.4', 'mac-address' => '02:00:00:00:00:02'],
            [],
        ];
        yield '/ipv6/dhcp-server/binding' => [
            '/ipv6/dhcp-server/binding',
            ['address' => '2001:db8::1/128', 'duid' => '0x0001', 'iaid' => '1'],
            [],
        ];
        yield '/ipv6/firewall/address-list' => [
            '/ipv6/firewall/address-list',
            ['list' => 'lossik-live-menu', 'address' => '2001:db8::2/128'],
            [],
        ];
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string> $printedDifferently
     */
    #[DataProvider('menus')]
    public function testAddPrintUpdateDeleteOnTheMenu(string $path, array $attributes, array $printedDifferently): void
    {
        $menu = $this->client()->menu($path);
        $mine = Query::where('comment', self::TAG);
        $router = $this->router();

        $id = $menu->add($attributes + ['comment' => self::TAG]);
        $printed = $menu->print($mine);
        $byApi = $router->rows($path . '/print', ['?comment=' . self::TAG]);
        $updated = $menu->update($mine, ['disabled' => 'yes']);
        $disabledByApi = $router->rows($path . '/print', ['?comment=' . self::TAG, '=.proplist=disabled']);
        $deleted = $menu->delete($mine);
        $leftByApi = $router->rows($path . '/print', ['?comment=' . self::TAG]);
        $router->close();

        self::assertMatchesRegularExpression('/^\*[0-9A-F]+$/', (string) $id);
        self::assertCount(1, $printed);
        self::assertCount(1, $byApi);
        self::assertSame($id, $printed[0]['.id']);
        self::assertSame(self::TAG, $printed[0]['comment'] ?? null);
        foreach (array_diff(array_keys($printed[0]), $printedDifferently) as $key) {
            self::assertSame($byApi[0][$key] ?? null, $printed[0][$key], $key);
        }
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
}
