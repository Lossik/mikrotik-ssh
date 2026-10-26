<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests;

/**
 * Real console output for the tests (outside the plan, F-C2: the fixture helper). Every file under Fixtures/console is
 * the `OUT` of one block of a probe run against a CHR, copied verbatim: name = RouterOS version + label of the block
 * (`6.49.23-ser3-addresslist.txt` = the block `ser3-addresslist` of `c-out-6.49.23-console2.txt`, task 55e7667a). The
 * probe wrote the bytes in the notation of `c_show()` (`probe/c-lib.php`): `\r`, `\n`, `\t`, `\\` and `\xHH` for any
 * other byte that is not printable ASCII nor part of valid UTF-8; everything else is the byte itself.
 */
final class ConsoleFixture
{
    public static function load(string $name): string
    {
        $file = __DIR__ . '/Fixtures/console/' . $name . '.txt';
        $shown = file_get_contents($file);
        if ($shown === false) {
            throw new \RuntimeException('Missing fixture ' . $file);
        }

        return self::decode(rtrim($shown, "\r\n"));
    }

    /** The bytes behind the `c_show()` notation. */
    public static function decode(string $shown): string
    {
        return (string) preg_replace_callback(
            '/\\\\(?:x([0-9A-F]{2})|(.))/s',
            static fn (array $m): string => $m[1] !== ''
                ? pack('H2', $m[1])
                : match ($m[2]) {
                    'r' => "\r",
                    'n' => "\n",
                    't' => "\t",
                    '\\' => '\\',
                    default => throw new \UnexpectedValueException('Not c_show notation: \\' . $m[2]),
                },
            $shown,
        );
    }
}
