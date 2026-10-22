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

    private const KEYS = ['SSHD_HOST', 'SSHD_PORT', 'SSHD_USER', 'SSHD_PASS', 'SSHD_SILENT_USER', 'SSHD_SILENT_PASS'];

    /**
     * @return array{
     *     SSHD_HOST: string,
     *     SSHD_PORT: string,
     *     SSHD_USER: string,
     *     SSHD_PASS: string,
     *     SSHD_SILENT_USER: string,
     *     SSHD_SILENT_PASS: string,
     * }
     */
    public static function settings(): array
    {
        $file = is_file(self::ENV_FILE) ? parse_ini_file(self::ENV_FILE, false, INI_SCANNER_RAW) : false;
        $values = [];
        foreach (self::KEYS as $key) {
            $value = is_array($file) ? ($file[$key] ?? null) : null;
            if (!is_string($value) || $value === '') {
                Assert::fail('.cache/sshd/env is missing or incomplete; run bash tools/sshd/start.sh first');
            }
            $values[$key] = $value;
        }

        return [
            'SSHD_HOST' => $values['SSHD_HOST'],
            'SSHD_PORT' => $values['SSHD_PORT'],
            'SSHD_USER' => $values['SSHD_USER'],
            'SSHD_PASS' => $values['SSHD_PASS'],
            'SSHD_SILENT_USER' => $values['SSHD_SILENT_USER'],
            'SSHD_SILENT_PASS' => $values['SSHD_SILENT_PASS'],
        ];
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
