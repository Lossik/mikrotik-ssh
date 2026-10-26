<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh;

/** Selects the structured or legacy text console protocol from RouterOS version output. */
final class ConsolePath
{
    /** @return array{path: 'json'|'text', version: ?string} */
    public static function fromVersion(string $output): array
    {
        if (
            preg_match('//u', $output) !== 1
            || preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', trim($output), $match) !== 1
        ) {
            return ['path' => 'text', 'version' => null];
        }
        $version = $match[1] . '.' . $match[2] . '.' . ($match[3] ?? '0');

        return [
            'path' => ((int) $match[1] > 7 || ((int) $match[1] === 7 && (int) $match[2] >= 17)) ? 'json' : 'text',
            'version' => $version,
        ];
    }
}
