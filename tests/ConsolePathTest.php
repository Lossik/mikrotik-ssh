<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests;

use Lossik\Mikrotik\Ssh\ConsolePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsolePathTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function versions(): iterable
    {
        $textVersions = [
            '6.34 (stable)',
            '6.49.23 (stable)',
            '7.1 (testing)',
            '7.12.2 (stable)',
            '7.13.5 (stable)',
            '7.16.2 (stable)',
        ];
        foreach ($textVersions as $version) {
            yield $version => [$version, 'text'];
        }
        $jsonVersions = [
            '7.17 (stable)',
            '7.17.2 (stable)',
            '7.24.5 (stable)',
            '7.17rc1 (testing)',
            '7.17beta2 (testing)',
            '7.18rc1 (testing)',
            '8.0beta1',
        ];
        foreach ($jsonVersions as $version) {
            yield $version => [$version, 'json'];
        }
        foreach (['', 'RouterOS', "\xF8\xFF", 'bad command name version'] as $unknown) {
            yield 'unknown ' . bin2hex($unknown) => [$unknown, 'text'];
        }
    }

    #[DataProvider('versions')]
    public function testPathSelection(string $output, string $path): void
    {
        self::assertSame($path, ConsolePath::fromVersion($output)['path']);
    }
}
