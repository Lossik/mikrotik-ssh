<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\Query;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * add, set, enable, disable and remove through the console (plan L12, A7, FAQ-5b): the ID of a new item, and verbs
 * that print nothing when they succeed (what they print otherwise is a TrapException, see ErrorsLiveTest).
 */
#[Group('live')]
#[Medium]
final class VerbsLiveTest extends LiveTestCase
{
    private const MENU = '/ip/firewall/address-list';

    public function testAddReturnsTheIdOfTheNewItem(): void
    {
        $menu = $this->client()->menu(self::MENU);

        $id = $menu->add(['list' => self::LIST, 'address' => '10.97.1.1', 'comment' => self::TAG]);

        $router = $this->router();
        $items = $router->rows(self::MENU . '/print', ['?list=' . self::LIST, '=.proplist=.id,address']);
        $router->close();
        self::assertMatchesRegularExpression('/^\*[0-9A-F]+$/', (string) $id);
        self::assertSame([['.id' => $id, 'address' => '10.97.1.1']], $items);
    }

    /**
     * L4 over the console: one `.id` of several items (`*a,*b,*c`) reaches `numbers=` and the router takes it.
     */
    public function testSeveralItemsAreChangedDisabledEnabledAndRemovedByOneId(): void
    {
        $menu = $this->client()->menu(self::MENU);
        $mine = Query::where('list', self::LIST);
        foreach (['10.97.1.1', '10.97.1.2', '10.97.1.3'] as $address) {
            $menu->add(['list' => self::LIST, 'address' => $address, 'comment' => self::TAG]);
        }
        $ids = array_column($menu->print($mine, ['.id']), '.id');
        $joined = implode(',', $ids);

        $changed = $menu->update($mine, ['comment' => self::TAG . '-changed']);
        $afterSet = $this->column('comment');
        $menu->run('disable', ['.id' => $joined]);
        $afterDisable = $this->column('disabled');
        $menu->run('enable', ['.id' => $joined]);
        $afterEnable = $this->column('disabled');
        $removed = $menu->delete($mine);
        $afterRemove = $this->column('disabled');

        self::assertCount(3, $ids);
        self::assertSame(3, $changed);
        self::assertSame(array_fill_keys($ids, self::TAG . '-changed'), $afterSet);
        self::assertSame(array_fill_keys($ids, 'true'), $afterDisable);
        self::assertSame(array_fill_keys($ids, 'false'), $afterEnable);
        self::assertSame(3, $removed);
        self::assertSame([], $afterRemove);
    }

    /**
     * FAQ-5b: set, enable, disable and remove print nothing on success, so their Result is empty (any output would
     * have been a TrapException).
     */
    public function testVerbsThatSucceedPrintNothing(): void
    {
        $transport = $this->transport();
        $id = $transport->execute(
            new Command(self::MENU, 'add', ['list' => self::LIST, 'address' => '10.97.1.4']),
        )->ret;
        self::assertNotNull($id);

        foreach (
            [
                new Command(self::MENU, 'set', ['.id' => $id, 'comment' => 'x']),
                new Command(self::MENU, 'disable', ['.id' => $id]),
                new Command(self::MENU, 'enable', ['.id' => $id]),
                new Command(self::MENU, 'remove', ['.id' => $id]),
            ] as $command
        ) {
            $result = $transport->execute($command);

            self::assertSame([], $result->rows, $command->verb);
            self::assertNull($result->ret, $command->verb);
        }
    }

    /**
     * @return array<string, string> the value of one column of the items of the list, by `.id`
     */
    private function column(string $name): array
    {
        $router = $this->router();
        $values = [];
        foreach ($router->rows(self::MENU . '/print', ['?list=' . self::LIST]) as $row) {
            $values[$row['.id']] = $row[$name] ?? '';
        }
        $router->close();

        return $values;
    }
}
