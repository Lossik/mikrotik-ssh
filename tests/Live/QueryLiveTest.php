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
 * Query::where() does not know the type of the attribute. `true`, `false`, `yes` and `no` are written so that they
 * select a boolean attribute (`disabled`, `dynamic`, `running`) and a string attribute that holds that very word,
 * the way the API does (see CliCompiler::comparand()).
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

    /**
     * Where on `true`, `false`, `yes` and `no`: a boolean attribute (`disabled` of an address list, `running` of an
     * interface, `dynamic` of a route) and a string attribute holding one of the words (`comment`; the items hold
     * each word and `other`, so "yes" must not select the one that says "true"). The oracle is the API's `?name=word`.
     */
    public function testBooleanWordsSelectWhatTheApiSelects(): void
    {
        $router = $this->router();
        $items = [
            ['10.97.3.1', 'yes', 'no'],
            ['10.97.3.2', 'true', 'no'],
            ['10.97.3.3', 'no', 'yes'],
            ['10.97.3.4', 'false', 'yes'],
            ['10.97.3.5', 'other', 'no'],
        ];
        foreach ($items as [$address, $comment, $disabled]) {
            $router->add(
                self::LISTED,
                ['list' => self::LIST, 'address' => $address, 'comment' => $comment, 'disabled' => $disabled],
            );
        }
        $queries = [];
        foreach (['true', 'false', 'yes', 'no'] as $word) {
            $queries[] = [self::LISTED, 'disabled', $word, 'address'];
            $queries[] = [self::LISTED, 'comment', $word, 'address'];
        }
        $queries[] = ['/interface', 'running', 'true', 'name'];
        $queries[] = ['/interface', 'running', 'yes', 'name'];
        $queries[] = ['/ip/route', 'dynamic', 'true', 'dst-address'];
        $queries[] = ['/ip/route', 'dynamic', 'yes', 'dst-address'];

        $byApi = [];
        $bySsh = [];
        foreach ($queries as [$path, $attribute, $word, $column]) {
            $label = $path . ' ' . $attribute . '=' . $word;
            $byApi[$label] = array_column(
                $router->rows($path . '/print', ['?' . $attribute . '=' . $word, '=.proplist=' . $column]),
                $column,
            );
            $bySsh[$label] = array_column(
                $this->client()->menu($path)->print(Query::where($attribute, $word), [$column]),
                $column,
            );
        }
        $router->close();

        foreach ($byApi as $label => $selected) {
            self::assertNotSame([], $selected, $label . ' selects something, or the row proves nothing');
        }
        self::assertSame($byApi, $bySsh);
    }
}
