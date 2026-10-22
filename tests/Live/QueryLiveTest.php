<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * The conditions of a Query as the console's `where` evaluates them (plan L12; F4 left the translations to the
 * router). The expected items are the ones the API returns for the same condition (checked in the F5b probe).
 *
 * Not covered: Query::where() on a boolean attribute (`disabled`, `dynamic`, ...) - the console compares a quoted
 * "true" with a boolean and matches nothing (see probe/f5b-results.md).
 */
#[Group('live')]
#[Medium]
final class QueryLiveTest extends LiveTestCase
{
    private const LISTED = '/ip/firewall/address-list';

    private const QUEUES = '/queue/tree';

    /**
     * Four items: .1 comment x, .2 no comment, .3 comment y (disabled), .4 comment x.
     *
     * @return iterable<string, array{Query, list<string>}> the query, the addresses of the items it selects
     */
    public static function conditions(): iterable
    {
        $list = Query::where('list', self::LIST);
        yield 'equals' => [Query::and($list, Query::where('comment', 'x')), ['10.97.2.1', '10.97.2.4']];
        yield 'has' => [Query::and($list, Query::has('comment')), ['10.97.2.1', '10.97.2.3', '10.97.2.4']];
        yield 'has not' => [Query::and($list, Query::hasNot('comment')), ['10.97.2.2']];
        yield 'equals the empty string' => [Query::and($list, Query::where('comment', '')), []];
        yield 'or' => [
            Query::and($list, Query::or(Query::where('address', '10.97.2.1'), Query::where('address', '10.97.2.3'))),
            ['10.97.2.1', '10.97.2.3'],
        ];
        yield 'and of three' => [
            Query::and($list, Query::where('comment', 'x'), Query::where('address', '10.97.2.4')),
            ['10.97.2.4'],
        ];
        yield 'or of an and and an equals' => [
            Query::and($list, Query::or(
                Query::and(Query::where('comment', 'x'), Query::where('address', '10.97.2.4')),
                Query::where('address', '10.97.2.3'),
            )),
            ['10.97.2.3', '10.97.2.4'],
        ];
        yield 'not equals (an item without the attribute is not equal)' => [
            Query::and($list, Query::not(Query::where('comment', 'x'))),
            ['10.97.2.2', '10.97.2.3'],
        ];
        yield 'not has' => [Query::and($list, Query::not(Query::has('comment'))), ['10.97.2.2']];
        yield 'not and' => [
            Query::and(
                $list,
                Query::not(Query::and(Query::where('comment', 'x'), Query::where('address', '10.97.2.1'))),
            ),
            ['10.97.2.2', '10.97.2.3', '10.97.2.4'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('conditions')]
    public function testConditionSelectsTheItemsTheApiSelects(Query $query, array $expected): void
    {
        $router = $this->router();
        $router->add(self::LISTED, ['list' => self::LIST, 'address' => '10.97.2.1', 'comment' => 'x']);
        $router->add(self::LISTED, ['list' => self::LIST, 'address' => '10.97.2.2']);
        $router->add(
            self::LISTED,
            ['list' => self::LIST, 'address' => '10.97.2.3', 'comment' => 'y', 'disabled' => 'yes'],
        );
        $router->add(self::LISTED, ['list' => self::LIST, 'address' => '10.97.2.4', 'comment' => 'x']);
        $router->close();

        $rows = $this->client()->menu(self::LISTED)->print($query);

        self::assertSame($expected, array_column($rows, 'address'));
    }

    /**
     * Three queues with a max-limit of 9, 10 and 100. A quoted number is compared as a number: as text "9" > "20".
     *
     * @return iterable<string, array{Query, list<string>}> the query, the names of the queues it selects
     */
    public static function numericConditions(): iterable
    {
        $mine = Query::where('comment', self::TAG);
        yield 'less than' => [
            Query::and($mine, Query::less('max-limit', '20')),
            ['lossik-live-q9', 'lossik-live-q10'],
        ];
        yield 'greater than' => [Query::and($mine, Query::greater('max-limit', '20')), ['lossik-live-q100']];
        yield 'equals' => [Query::and($mine, Query::where('max-limit', '10')), ['lossik-live-q10']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('numericConditions')]
    public function testNumbersAreComparedAsNumbers(Query $query, array $expected): void
    {
        $router = $this->router();
        foreach ([9, 10, 100] as $limit) {
            $router->add(self::QUEUES, [
                'name' => 'lossik-live-q' . $limit,
                'parent' => 'global',
                'max-limit' => (string) $limit,
                'comment' => self::TAG,
            ]);
        }
        $router->close();

        $rows = $this->client()->menu(self::QUEUES)->print($query);

        self::assertSame($expected, array_column($rows, 'name'));
    }
}
