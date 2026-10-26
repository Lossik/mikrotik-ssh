<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\AuthenticationException;
use Lossik\DeviceCommunication\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * Logging in to the console of a real RouterOS (plan L12, S4).
 */
#[Group('live')]
#[Medium]
final class SessionLiveTest extends LiveTestCase
{
    /**
     * S4: a user without a password logs in with the empty string, and it is a session that runs commands.
     */
    public function testUserWithAnEmptyPasswordLogsIn(): void
    {
        $transport = $this->transport('nopass', '');

        $rows = $transport->execute(new Command('/ip/address', 'print', [], null, ['address']))->rows;

        self::assertNotSame([], $rows);
        self::assertArrayHasKey('address', $rows[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedLogins(): iterable
    {
        yield 'wrong password' => ['admin', 'not-the-password'];
        yield 'empty password of a user that has one' => ['admin', ''];
        yield 'user that does not exist' => ['lossik-live-nobody', ''];
    }

    /**
     * S4: a refused login is an AuthenticationException (the router answers about 1 s late, so the test cannot
     * be a ConnectionException of a connect timeout).
     */
    #[DataProvider('rejectedLogins')]
    public function testRefusedLoginIsAnAuthenticationException(string $user, string $password): void
    {
        $this->expectException(AuthenticationException::class);

        $this->transport($user, $password);
    }
}
