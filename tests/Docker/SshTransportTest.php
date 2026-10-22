<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Docker;

use Lossik\DeviceCommunication\AuthenticationException;
use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\DeviceCommunication\TrapException;
use Lossik\Mikrotik\Ssh\SshOptions;
use Lossik\Mikrotik\Ssh\SshTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * SshTransport against the OpenSSH of tools/sshd/start.sh. It is not a RouterOS, so only what does not depend on the
 * RouterOS console is tested here; the console itself (S3, S4, the shape of print) is tested on a CHR (tests/Live).
 */
#[Group('docker')]
#[Medium]
final class SshTransportTest extends TestCase
{
    /**
     * S5: the cause of a failed login decides the exception. AuthenticationException and ConnectionException are
     * unrelated classes, so asserting the expected one also rules out the other.
     *
     * @return iterable<string, array{string, class-string<AuthenticationException|ConnectionException>}>
     */
    public static function failedLogins(): iterable
    {
        yield 'nothing listens on the port' => ['closed port', ConnectionException::class];
        yield 'server accepts TCP and never sends a banner' => ['silent server', TimeoutException::class];
        yield 'wrong password' => ['wrong password', AuthenticationException::class];
    }

    /**
     * @param class-string<AuthenticationException|ConnectionException> $expected
     */
    #[DataProvider('failedLogins')]
    public function testLoginFailureIsTypedByItsCause(string $cause, string $expected): void
    {
        $sshd = Sshd::settings();
        $port = (int) $sshd['SSHD_PORT'];
        $password = $sshd['SSHD_PASS'];
        // OpenSSH delays a rejected password by about 2 s, which must not look like a connect timeout
        $connectTimeout = 10.0;
        $keepOpen = null;
        if ($cause === 'closed port') {
            $server = stream_socket_server('tcp://127.0.0.1:0');
            self::assertNotFalse($server);
            $port = Sshd::port($server);
            fclose($server);
        } elseif ($cause === 'silent server') {
            // never accepted: the kernel completes the TCP handshake from the backlog, nothing is ever sent
            $keepOpen = stream_socket_server('tcp://127.0.0.1:0');
            self::assertNotFalse($keepOpen);
            $port = Sshd::port($keepOpen);
            $connectTimeout = 1.0;
        } else {
            $password = bin2hex(random_bytes(12));
        }

        $caught = null;
        $started = hrtime(true);
        try {
            SshTransport::connect(
                '127.0.0.1',
                $sshd['SSHD_USER'],
                $password,
                new SshOptions(port: $port, connectTimeout: $connectTimeout),
            );
        } catch (ConnectionException | AuthenticationException $e) {
            $caught = $e;
        } finally {
            if (is_resource($keepOpen)) {
                fclose($keepOpen);
            }
        }
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertInstanceOf($expected, $caught);
        if ($caught instanceof TimeoutException) {
            self::assertSame('connectTimeout', $caught->limit);
        }
        // default_socket_timeout is 60 s: the limit must come from connectTimeout
        self::assertLessThan(8.0, $elapsed);
    }

    /**
     * S7
     */
    public function testCommandAfterCloseIsAConnectionErrorAndCloseIsIdempotent(): void
    {
        $transport = self::connect();
        self::assertTrue($transport->isOpen());

        $transport->close();
        $transport->close();

        self::assertFalse($transport->isOpen());
        $this->expectException(ConnectionException::class);
        $transport->execute(new Command('/ip/address', 'print'));
    }

    /**
     * A bash that does not know ":do" prints its complaint like the RouterOS console does for a line it does not
     * accept (no marker either): the complaint is a refusal, never a successful empty result (2.x, A7).
     */
    public function testMessageOfTheServerForALineItDidNotAcceptIsATrapAndTheTransportStaysOpen(): void
    {
        $transport = self::connect();

        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('TrapException expected');
        } catch (TrapException) {
            self::assertTrue($transport->isOpen());
        }
    }

    private static function connect(): SshTransport
    {
        $sshd = Sshd::settings();

        return SshTransport::connect(
            $sshd['SSHD_HOST'],
            $sshd['SSHD_USER'],
            $sshd['SSHD_PASS'],
            new SshOptions(port: (int) $sshd['SSHD_PORT']),
        );
    }
}
