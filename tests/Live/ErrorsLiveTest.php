<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\TrapException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * What a command the console refuses becomes (plan L12, S3, U13, A7): a TrapException that says why it failed, and the
 * session stays usable. Two forms of refusal exist on RouterOS (measured on 7.24.5 and 7.23.8): an error while the
 * command runs is caught by the `:onerror` of the wrapper, which prints the marker and the reason the console gives
 * ("no such item (4) (/ip/address/set; line 1)"); a line the console cannot parse is refused before anything runs
 * and prints its own message ("syntax error (line 1 column 22)").
 */
#[Group('live')]
#[Medium]
final class ErrorsLiveTest extends LiveTestCase
{
    /**
     * OL8 (plan §3.1, L175/L183, L453): only the presence of the reason is asserted, not the 7.24.5 suffix
     * `(/ip/address/set; line 1)` or the phrasing that differs by version (`bad parameter bogus` on 7.23.8/7.24.5 vs
     * `expected end of command` on <= 7.17.2; `bad command name nonexistent` on the spaced text path vs `syntax error`
     * on the slash JSON path).
     *
     * @return iterable<string, array{Command, string, string}> the command, the pattern of the message, the user
     */
    public static function refusedCommands(): iterable
    {
        yield 'unknown verb' => [
            new Command('/ip/address', 'nonexistent'),
            '~^bad command name nonexistent \(line 1 column \d+\)~',
            'admin',
        ];
        yield 'unknown item' => [
            new Command('/ip/address', 'set', ['.id' => '*FFFF', 'comment' => 'x']),
            '~^no such item \(4\)~',
            'admin',
        ];
        yield 'item to remove does not exist' => [
            new Command('/ip/address', 'remove', ['.id' => '*FFFF']),
            '~^no such item \(4\)~',
            'admin',
        ];
        yield 'value that is not valid' => [
            new Command('/ip/address', 'add', ['address' => 'not-an-address', 'interface' => 'ether1']),
            '~^invalid value for argument address~',
            'admin',
        ];
        yield 'mandatory attribute missing' => [
            new Command('/ip/address', 'add', ['address' => '10.97.9.1/24']),
            '~^Script Error: missing value\(s\) of argument\(s\) interface~',
            'admin',
        ];
        yield 'attribute that does not exist' => [
            new Command('/ip/address', 'add', ['address' => '10.97.9.1/24', 'interface' => 'ether1', 'bogus' => '1']),
            '~^(?:bad parameter bogus|expected end of command) \(line 1 column \d+\)~',
            'admin',
        ];
        yield 'write by a user of the group read' => [
            new Command('/ip/address', 'add', ['address' => '10.97.9.1/24', 'interface' => 'ether1']),
            '~^not enough permissions \(9\)~',
            'nopass',
        ];
        // a line the console refuses before it runs prints its own message, not the marker; a verb that is not
        // `print` must not print anything (FAQ-5b), so the message is the refusal
        yield 'menu that does not exist, print' => [
            new Command('/ip/nonexistent', 'print'),
            '~^(?:bad command name nonexistent|syntax error) \(line 1 column \d+\)~',
            'admin',
        ];
        yield 'menu that does not exist, add' => [
            new Command('/ip/nonexistent', 'add', ['name' => 'x']),
            '~^(?:bad command name nonexistent|syntax error) \(line 1 column \d+\)~',
            'admin',
        ];
        yield 'menu that does not exist, set' => [
            new Command('/ip/nonexistent', 'set', ['.id' => '*1', 'name' => 'x']),
            '~^(?:bad command name nonexistent|syntax error) \(line 1 column \d+\)~',
            'admin',
        ];
    }

    #[DataProvider('refusedCommands')]
    public function testRefusedCommandIsATrapAndTheSessionStaysUsable(
        Command $command,
        string $message,
        string $user,
    ): void {
        $transport = $user === 'admin' ? $this->transport() : $this->transport($user, '');

        try {
            $transport->execute($command);
            self::fail('TrapException expected');
        } catch (TrapException $e) {
            self::assertMatchesRegularExpression($message, $e->getMessage());
            self::assertNull($e->category);
            self::assertSame([], $e->traps());
        }

        self::assertTrue($transport->isOpen());
        $rows = $transport->execute(new Command('/ip/address', 'print', [], null, ['address']))->rows;
        self::assertNotSame([], $rows);
    }
}
