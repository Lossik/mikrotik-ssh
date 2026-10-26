<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\ConnectionException;
use Lossik\Mikrotik\Ssh\FileTransfer;
use Lossik\Mikrotik\Ssh\SshOptions;
use Lossik\Mikrotik\Ssh\SshTransport;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The router the live tests run against, from the variables tools/chr/run-live.sh exports. A missing variable fails
 * the test: a live run that silently tests nothing is worse than a red one.
 */
final class Env
{
    private const VARIABLES = [
        'MIKROTIK_LIVE_HOST',
        'MIKROTIK_LIVE_PORT_API',
        'MIKROTIK_LIVE_PORT_TLS',
        'MIKROTIK_LIVE_PORT_SSH',
        'MIKROTIK_LIVE_USER',
        'MIKROTIK_LIVE_PASS',
        'MIKROTIK_LIVE_SSH_PATH',
        'CHR_VERSION',
    ];

    /** How long the first login may keep failing with a ConnectionException (seconds), and the pause between tries. */
    private const FIRST_LOGIN_PATIENCE = 30.0;

    private const FIRST_LOGIN_PAUSE = 2;

    /** Set by the first login that worked; from then on a failed connect is a failure, not a slow boot. */
    private static bool $routerAnswersLogins = false;

    public function __construct(
        public readonly string $host,
        public readonly int $apiPort,
        public readonly int $tlsPort,
        public readonly int $sshPort,
        public readonly string $user,
        public readonly string $password,
        public readonly string $sshPath,
        public readonly string $chrVersion,
    ) {
    }

    /**
     * @throws AssertionFailedError a variable is missing, empty or (a port) not a number
     */
    public static function load(): self
    {
        /** @var array<string, string> $values */
        $values = [];
        $missing = [];
        foreach (self::VARIABLES as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                $missing[] = $name;
            }
            $values[$name] = $value === false ? '' : $value;
        }
        if ($missing !== []) {
            throw new AssertionFailedError(
                'The live tests need the environment variables set by tools/chr/run-live.sh; missing: '
                . implode(', ', $missing),
            );
        }
        foreach (['MIKROTIK_LIVE_PORT_API', 'MIKROTIK_LIVE_PORT_TLS', 'MIKROTIK_LIVE_PORT_SSH'] as $name) {
            if (!ctype_digit($values[$name])) {
                throw new AssertionFailedError(sprintf('%s is not a port number: %s', $name, $values[$name]));
            }
        }

        return new self(
            $values['MIKROTIK_LIVE_HOST'],
            (int) $values['MIKROTIK_LIVE_PORT_API'],
            (int) $values['MIKROTIK_LIVE_PORT_TLS'],
            (int) $values['MIKROTIK_LIVE_PORT_SSH'],
            $values['MIKROTIK_LIVE_USER'],
            $values['MIKROTIK_LIVE_PASS'],
            $values['MIKROTIK_LIVE_SSH_PATH'],
            $values['CHR_VERSION'],
        );
    }

    /**
     * The limit of a reply is short on purpose (the default 60 s would outlast the 10 s of a medium test), yet far
     * above the milliseconds a command takes on the emulated router.
     */
    public function options(float $responseTimeout = 8.0, string $charset = 'UTF-8'): SshOptions
    {
        return new SshOptions(
            port: $this->sshPort,
            connectTimeout: 10.0,
            responseTimeout: $responseTimeout,
            charset: $charset,
        );
    }

    /**
     * A logged-in console; user and password default to the admin of the router.
     *
     * @throws \Lossik\DeviceCommunication\CommunicationException
     */
    public function connect(?string $user = null, ?string $password = null, ?SshOptions $options = null): SshTransport
    {
        return $this->retryingTheFirstLogin(fn (): SshTransport => SshTransport::connect(
            $this->host,
            $user ?? $this->user,
            $password ?? $this->password,
            $options ?? $this->options(),
        ));
    }

    /**
     * @throws \Lossik\DeviceCommunication\CommunicationException
     */
    public function connectFiles(?string $user = null, ?string $password = null): FileTransfer
    {
        return $this->retryingTheFirstLogin(fn (): FileTransfer => FileTransfer::connect(
            $this->host,
            $user ?? $this->user,
            $password ?? $this->password,
            $this->options(),
        ));
    }

    /**
     * A freshly booted CHR may refuse the first SSH login for a few seconds although it has sent its banner (seen on
     * a slow runner; 2.x commit 8b845c6). Only a ConnectionException is retried, for at most 30 s, and only until a
     * login has worked once: a wrong password (AuthenticationException) fails at once. Every retry is written to
     * stderr, so a run shows whether it ever happened and what failed.
     *
     * @template T
     * @param callable(): T $connect
     * @return T
     */
    private function retryingTheFirstLogin(callable $connect): mixed
    {
        $deadline = microtime(true) + self::FIRST_LOGIN_PATIENCE;
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $connection = $connect();
                self::$routerAnswersLogins = true;

                return $connection;
            } catch (ConnectionException $e) {
                if (self::$routerAnswersLogins || microtime(true) + self::FIRST_LOGIN_PAUSE > $deadline) {
                    throw $e;
                }
                fwrite(STDERR, sprintf(
                    "\nlive: first SSH login, attempt %d failed (%s: %s), retrying in %d s\n",
                    $attempt,
                    $e::class,
                    $e->getMessage(),
                    self::FIRST_LOGIN_PAUSE,
                ));
                sleep(self::FIRST_LOGIN_PAUSE);
            }
        }
    }
}
