<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Query;
use Lossik\DeviceCommunication\UnsupportedOperationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * The conditions of a Query as the console's `where` evaluates them (plan L12, OL5; F4 left the translations to the
 * router). The expected items are the ones the API returns for the same condition.
 *
 * Query::where() does not know the type of the attribute. `true`, `false`, `yes` and `no` are written so that they
 * select a boolean attribute (`disabled`, `dynamic`, `running`) and a string attribute that holds that very word,
 * also under `Not` (Q9 (e), D12), the way the API does.
 */
#[Group('live')]
#[Medium]
final class QueryLiveTest extends LiveTestCase
{
    private const LISTED = '/ip/firewall/address-list';

    private const QUEUES = '/queue/tree';

    private const NON_ASCII = 'Příliš';

    /**
     * Four items: .1 comment x, .2 no comment, .3 comment Příliš (disabled), .4 comment x.
     *
     * @return iterable<string, array{0: Query, 1: list<string>, 2: list<string>, 3?: list<string>}>
     *         the query, the API query words after `?list=…`, the addresses of the items the API selects, and the
     *         addresses SSH selects where they differ from the API (otherwise SSH must select what the API selects;
     *         the API selects them too below RouterOS 6.42)
     */
    public static function conditions(): iterable
    {
        $list = Query::where('list', self::LIST);
        $all = ['10.97.2.1', '10.97.2.2', '10.97.2.3', '10.97.2.4'];
        yield 'equals' => [
            Query::and($list, Query::where('comment', 'x')),
            ['?comment=x', '?#&'],
            ['10.97.2.1', '10.97.2.4'],
        ];
        yield 'equals non-ASCII' => [
            Query::and($list, Query::where('comment', self::NON_ASCII)),
            ['?comment=' . self::NON_ASCII, '?#&'],
            ['10.97.2.3'],
        ];
        yield 'has' => [
            Query::and($list, Query::has('comment')),
            ['?comment', '?#&'],
            ['10.97.2.1', '10.97.2.3', '10.97.2.4'],
        ];
        yield 'has not' => [
            Query::and($list, Query::hasNot('comment')),
            ['?-comment', '?#&'],
            ['10.97.2.2'],
        ];
        yield 'has on a boolean is existence, not truth' => [
            Query::and($list, Query::has('disabled')),
            ['?disabled', '?#&'],
            $all,
        ];
        yield 'has not on a boolean' => [
            Query::and($list, Query::hasNot('disabled')),
            ['?-disabled', '?#&'],
            [],
        ];
        yield 'has on an IP address' => [
            Query::and($list, Query::has('address')),
            ['?address', '?#&'],
            $all,
        ];
        yield 'has not on an IP address' => [
            Query::and($list, Query::hasNot('address')),
            ['?-address', '?#&'],
            [],
        ];
        yield 'equals the empty string' => [
            Query::and($list, Query::where('comment', '')),
            ['?comment=', '?#&'],
            [],
        ];
        yield 'less than IPv4' => [
            Query::and($list, Query::less('address', '10.97.2.3')),
            ['?<address=10.97.2.3', '?#&'],
            ['10.97.2.1', '10.97.2.2'],
        ];
        yield 'greater than IPv4' => [
            Query::and($list, Query::greater('address', '10.97.2.2')),
            ['?>address=10.97.2.2', '?#&'],
            ['10.97.2.3', '10.97.2.4'],
        ];
        // Q12 (a), user 26. 10. 2026: for an IPv4 operand the API compares an address-list address as text on
        // 6.42.12 ... 7.25rc3 and as an address on 6.34 (fx-live, 26. 10. 2026); the console (and so this library)
        // always as an address. A README limit. SSH is pinned to the address order (the fourth column) on every
        // version, the API by version to its measured order: the text order (the third column) from 6.42.12, the
        // address order on 6.34 (see the test).
        yield 'less than IPv4, a two-digit octet: the API compares text, SSH addresses (Q12 (a))' => [
            Query::and($list, Query::less('address', '10.97.2.10')),
            ['?<address=10.97.2.10', '?#&'],
            ['10.97.2.1'],
            $all,
        ];
        yield 'greater than IPv4, a two-digit octet: the API compares text, SSH addresses (Q12 (a))' => [
            Query::and($list, Query::greater('address', '10.97.2.10')),
            ['?>address=10.97.2.10', '?#&'],
            ['10.97.2.2', '10.97.2.3', '10.97.2.4'],
            [],
        ];
        yield 'or' => [
            Query::and($list, Query::or(Query::where('address', '10.97.2.1'), Query::where('address', '10.97.2.3'))),
            ['?address=10.97.2.1', '?address=10.97.2.3', '?#|', '?#&'],
            ['10.97.2.1', '10.97.2.3'],
        ];
        yield 'and of three' => [
            Query::and($list, Query::where('comment', 'x'), Query::where('address', '10.97.2.4')),
            ['?comment=x', '?address=10.97.2.4', '?#&', '?#&'],
            ['10.97.2.4'],
        ];
        yield 'or of an and and an equals' => [
            Query::and($list, Query::or(
                Query::and(Query::where('comment', 'x'), Query::where('address', '10.97.2.4')),
                Query::where('address', '10.97.2.3'),
            )),
            ['?comment=x', '?address=10.97.2.4', '?#&', '?address=10.97.2.3', '?#|', '?#&'],
            ['10.97.2.3', '10.97.2.4'],
        ];
        yield 'not equals (an item without the attribute is not equal)' => [
            Query::and($list, Query::not(Query::where('comment', 'x'))),
            ['?comment=x', '?#!', '?#&'],
            ['10.97.2.2', '10.97.2.3'],
        ];
        yield 'not has' => [
            Query::and($list, Query::not(Query::has('comment'))),
            ['?comment', '?#!', '?#&'],
            ['10.97.2.2'],
        ];
        yield 'not has not' => [
            Query::and($list, Query::not(Query::hasNot('comment'))),
            ['?-comment', '?#!', '?#&'],
            ['10.97.2.1', '10.97.2.3', '10.97.2.4'],
        ];
        yield 'not and' => [
            Query::and(
                $list,
                Query::not(Query::and(Query::where('comment', 'x'), Query::where('address', '10.97.2.1'))),
            ),
            ['?comment=x', '?address=10.97.2.1', '?#&', '?#!', '?#&'],
            ['10.97.2.2', '10.97.2.3', '10.97.2.4'],
        ];
    }

    /**
     * @param list<string> $apiWords
     * @param list<string> $expected
     * @param list<string>|null $sshExpected null: SSH selects what the API selects
     */
    #[DataProvider('conditions')]
    public function testConditionSelectsTheItemsTheApiSelects(
        Query $query,
        array $apiWords,
        array $expected,
        ?array $sshExpected = null,
    ): void {
        $router = $this->router();
        $router->add(self::LISTED, ['list' => self::LIST, 'address' => '10.97.2.1', 'comment' => 'x']);
        $router->add(self::LISTED, ['list' => self::LIST, 'address' => '10.97.2.2']);
        $router->add(
            self::LISTED,
            ['list' => self::LIST, 'address' => '10.97.2.3', 'comment' => self::NON_ASCII, 'disabled' => 'yes'],
        );
        $router->add(self::LISTED, ['list' => self::LIST, 'address' => '10.97.2.4', 'comment' => 'x']);
        $byApi = array_column(
            $router->rows(self::LISTED . '/print', ['?list=' . self::LIST, ...$apiWords, '=.proplist=address']),
            'address',
        );
        $router->close();

        $rows = $this->client()->menu(self::LISTED)->print($query);

        // An exception from "a test never asks the version" (like the 6.x exclusion of MenuLiveTest), a user decision
        // (Q12 (a) / #49, 26. 10. 2026): with an IPv4 operand the API selects the address order on RouterOS 6.34 and
        // the text order on every other version of the matrix (6.42.12 ... 7.25rc3).
        $apiExpected = $sshExpected !== null && version_compare($this->env->chrVersion, '6.42', '<')
            ? $sshExpected
            : $expected;
        self::assertSame($apiExpected, $byApi);
        self::assertSame($sshExpected ?? $byApi, array_column($rows, 'address'));
    }

    /**
     * `has()` and `hasNot()` over a property with an empty value, the `address` of /ip/service (empty on a fresh
     * router): the API `?address` is the existence of the property, also with an empty value, the console
     * `address~"."` needs a character, so SSH sees those services in `hasNot()`. A user decision (a) of 26. 10. 2026
     * (#51), a README limit (probe fy-has-empty on 7.24.5 and 6.49.18).
     *
     * The oracle is one API print of every service, compared by `.id` (names repeat: the dynamic `api` and `ssh`
     * rows of 7.x): SSH `has()` is exactly the services with a non-empty address, `hasNot()` exactly the rest. The
     * API `?address` / `?-address` are checked for what the live runs measured on 7.24.5, 6.49.18 and 6.34: `has`
     * selects something, covers every non-empty address, and the two split all services between them.
     */
    public function testHasOverAnEmptyValueIsExistenceOverTheApiAndANonEmptyValueOverSsh(): void
    {
        $router = $this->router();
        $all = [];
        $nonEmpty = [];
        foreach ($router->rows('/ip/service/print', ['=.proplist=.id,name,address']) as $row) {
            $all[] = $row['.id'];
            if (($row['address'] ?? '') !== '') {
                $nonEmpty[] = $row['.id'];
            }
        }
        $apiHas = self::ids($router->rows('/ip/service/print', ['?address', '=.proplist=.id']));
        $apiHasNot = self::ids($router->rows('/ip/service/print', ['?-address', '=.proplist=.id']));
        $router->close();
        $menu = $this->client()->menu('/ip/service');

        $has = self::ids($menu->print(Query::has('address'), ['.id']));
        $hasNot = self::ids($menu->print(Query::hasNot('address'), ['.id']));

        sort($all);
        sort($nonEmpty);
        $empty = array_values(array_diff($all, $nonEmpty));
        self::assertSame($nonEmpty, $has, 'SSH has(): the services with a non-empty address');
        self::assertSame($empty, $hasNot, 'SSH hasNot(): every other service');
        self::assertNotSame([], $apiHas);
        self::assertSame([], array_values(array_diff($nonEmpty, $apiHas)), 'API ?address covers every non-empty one');
        self::assertSame([], array_values(array_intersect($apiHas, $apiHasNot)), 'API ?address and ?-address');
        $split = [...$apiHas, ...$apiHasNot];
        sort($split);
        self::assertSame($all, $split, 'API ?address and ?-address split all services');
    }

    /**
     * @param list<array<string, string>> $rows
     * @return list<string> the `.id`s, sorted
     */
    private static function ids(array $rows): array
    {
        $ids = array_column($rows, '.id');
        sort($ids);

        return $ids;
    }

    /**
     * Three queues with a max-limit of 9, 10 and 100. A quoted number is compared as a number: as text "9" > "20".
     *
     * @return iterable<string, array{Query, list<string>, list<string>}>
     *         the query, the API query words after `?comment=…`, the names of the queues it selects
     */
    public static function numericConditions(): iterable
    {
        $mine = Query::where('comment', self::TAG);
        yield 'less than' => [
            Query::and($mine, Query::less('max-limit', '20')),
            ['?<max-limit=20', '?#&'],
            ['lossik-live-q9', 'lossik-live-q10'],
        ];
        yield 'greater than' => [
            Query::and($mine, Query::greater('max-limit', '20')),
            ['?>max-limit=20', '?#&'],
            ['lossik-live-q100'],
        ];
        yield 'equals' => [
            Query::and($mine, Query::where('max-limit', '10')),
            ['?max-limit=10', '?#&'],
            ['lossik-live-q10'],
        ];
        // Q12: `0` is a non-negative integer, accepted like any other
        yield 'greater than 0' => [
            Query::and($mine, Query::greater('max-limit', '0')),
            ['?>max-limit=0', '?#&'],
            ['lossik-live-q9', 'lossik-live-q10', 'lossik-live-q100'],
        ];
    }

    /**
     * @param list<string> $apiWords
     * @param list<string> $expected
     */
    #[DataProvider('numericConditions')]
    public function testNumbersAreComparedAsNumbers(Query $query, array $apiWords, array $expected): void
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
        $byApi = array_column(
            $router->rows(self::QUEUES . '/print', ['?comment=' . self::TAG, ...$apiWords, '=.proplist=name']),
            'name',
        );
        $router->close();

        $rows = $this->client()->menu(self::QUEUES)->print($query);

        self::assertSame($expected, $byApi);
        self::assertSame($byApi, array_column($rows, 'name'));
    }

    /**
     * Q12 (a): Less and Greater accept only non-negative integers (no leading zero except `0`) and IPv4 addresses
     * without prefix; anything else (string, time, IPv6, prefix, negative or decimal number, leading zero) is refused
     * with UnsupportedOperationException before anything is sent. That nothing is sent cannot be seen here (the unit
     * tests show it); what can: the session stays open, and the print after the refusals equals the API's.
     */
    public function testLessAndGreaterRefuseOperandsOtherThanNonNegativeIntegersAndIpv4(): void
    {
        $router = $this->router();
        $router->add(self::LISTED, ['list' => self::LIST, 'address' => '10.97.2.1']);
        $byApi = array_column(
            $router->rows(self::LISTED . '/print', ['?list=' . self::LIST, '=.proplist=address']),
            'address',
        );
        $router->close();
        $menu = $this->client()->menu(self::LISTED);
        $operands = [
            'string' => 'b',
            'empty' => '',
            'negative' => '-1',
            'plus sign' => '+5',
            'decimal' => '1.5',
            'leading zero' => '007',
            'time' => '1h',
            'IPv4 prefix' => '10.0.0.0/24',
            'IPv6' => '2001:db8::1',
            'IPv4 octet out of range' => '256.0.0.1',
        ];
        foreach ($operands as $label => $operand) {
            foreach ([Query::less('address', $operand), Query::greater('address', $operand)] as $query) {
                try {
                    $menu->print($query);
                    self::fail('UnsupportedOperationException expected for ' . $label);
                } catch (UnsupportedOperationException) {
                    self::assertTrue($this->transport()->isOpen());
                }
            }
        }

        self::assertSame(['10.97.2.1'], $byApi);
        self::assertSame($byApi, array_column($menu->print(Query::where('list', self::LIST)), 'address'));
    }

    /**
     * Where on `true`, `false`, `yes` and `no`: a boolean attribute (`disabled` of an address list, `running` of an
     * interface, `dynamic` of a route) and a string attribute holding one of the words (`comment`; the items hold
     * each word and `other`, so "yes" must not select the one that says "true"; one item has no comment, which `Not`
     * of a comment selects like the API, OL5), and `true`/`false` also under `Not` (Q9 (e), D12). The oracle is the
     * API.
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
            ['10.97.3.6', null, 'no'],
        ];
        foreach ($items as [$address, $comment, $disabled]) {
            $attributes = ['list' => self::LIST, 'address' => $address, 'disabled' => $disabled];
            $router->add(self::LISTED, $comment === null ? $attributes : $attributes + ['comment' => $comment]);
        }
        $queries = [];
        foreach (['true', 'false', 'yes', 'no'] as $word) {
            $queries[] = [self::LISTED, 'disabled', $word, 'address', false];
            $queries[] = [self::LISTED, 'comment', $word, 'address', false];
        }
        foreach (['true', 'false'] as $word) {
            $queries[] = [self::LISTED, 'disabled', $word, 'address', true];
            $queries[] = [self::LISTED, 'comment', $word, 'address', true];
        }
        $queries[] = ['/interface', 'running', 'true', 'name', false];
        $queries[] = ['/interface', 'running', 'yes', 'name', false];
        $queries[] = ['/ip/route', 'dynamic', 'true', 'dst-address', false];
        $queries[] = ['/ip/route', 'dynamic', 'yes', 'dst-address', false];

        $byApi = [];
        $bySsh = [];
        foreach ($queries as [$path, $attribute, $word, $column, $negated]) {
            $label = $path . ($negated ? ' !(' . $attribute . '=' . $word . ')' : ' ' . $attribute . '=' . $word);
            $apiWords = $negated
                ? ['?' . $attribute . '=' . $word, '?#!', '=.proplist=' . $column]
                : ['?' . $attribute . '=' . $word, '=.proplist=' . $column];
            $sshQuery = $negated ? Query::not(Query::where($attribute, $word)) : Query::where($attribute, $word);
            $byApi[$label] = array_column($router->rows($path . '/print', $apiWords), $column);
            $bySsh[$label] = array_column($this->client()->menu($path)->print($sshQuery, [$column]), $column);
        }
        $router->close();

        foreach ($byApi as $label => $selected) {
            self::assertNotSame([], $selected, $label . ' selects something, or the row proves nothing');
        }
        self::assertSame($byApi, $bySsh);
    }
}
