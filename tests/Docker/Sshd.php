<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Docker;

use PHPUnit\Framework\Assert;

/**
 * The OpenSSH server started by tools/sshd/start.sh in the same container. The settings come from .cache/sshd/env; a
 * missing file is a failure, not a skip: a docker run that tests nothing must not look green.
 */
final class Sshd
{
    private const ENV_FILE = __DIR__ . '/../../.cache/sshd/env';

    /**
     * @return array{SSHD_HOST: string, SSHD_PORT: string, SSHD_USER: string, SSHD_PASS: string}
     */
    public static function settings(): array
    {
        $settings = is_file(self::ENV_FILE) ? parse_ini_file(self::ENV_FILE, false, INI_SCANNER_RAW) : false;
        if (
            !is_array($settings)
            || !is_string($host = $settings['SSHD_HOST'] ?? null) || $host === ''
            || !is_string($port = $settings['SSHD_PORT'] ?? null) || $port === ''
            || !is_string($user = $settings['SSHD_USER'] ?? null) || $user === ''
            || !is_string($pass = $settings['SSHD_PASS'] ?? null) || $pass === ''
        ) {
            Assert::fail('.cache/sshd/env is missing or incomplete; run bash tools/sshd/start.sh first');
        }

        return ['SSHD_HOST' => $host, 'SSHD_PORT' => $port, 'SSHD_USER' => $user, 'SSHD_PASS' => $pass];
    }

    /**
     * @param resource $server
     */
    public static function port($server): int
    {
        $name = (string) stream_socket_get_name($server, false);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
