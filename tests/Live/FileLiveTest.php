<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\TrapException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * FileTransfer against the SFTP of RouterOS (plan L13): the root of the SFTP is the file storage of the router (the
 * `/file` menu), and only a user that may write there (admin) can use it.
 */
#[Group('live')]
#[Medium]
final class FileLiveTest extends LiveTestCase
{
    /** @var list<string> */
    private array $localFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->localFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function contents(): iterable
    {
        yield 'text' => ["line 1\nline 2\n"];
        yield 'binary with NUL and bytes that are not UTF-8' => ["A\0B\xFF\x80" . str_repeat("\x01\0\xFE", 2000)];
    }

    #[DataProvider('contents')]
    public function testFileSurvivesTheRoundTrip(string $content): void
    {
        $remote = self::FILE_PREFIX . 'file.bin';
        $source = $this->localFile($content);
        $copy = $this->localFile('');
        $files = $this->files();

        $files->upload($source, $remote);
        $written = $files->download($remote, $copy);
        $returned = $files->download($remote);
        $stored = $this->storedFile($remote, (string) strlen($content));

        self::assertSame([['name' => $remote, 'size' => (string) strlen($content)]], $stored);
        self::assertTrue($written);
        self::assertSame($content, file_get_contents($copy));
        self::assertSame($content, $returned);
    }

    public function testRefusedRequestIsATrapAndTheSessionStaysOpen(): void
    {
        $files = $this->files();

        try {
            $files->download(self::FILE_PREFIX . 'missing.txt');
            self::fail('TrapException expected');
        } catch (TrapException $e) {
            self::assertStringContainsString(self::FILE_PREFIX . 'missing.txt', $e->getMessage());
        }

        self::assertTrue($files->isOpen());
        $files->upload($this->localFile('abc'), self::FILE_PREFIX . 'after-trap.txt');
    }

    /**
     * The SSH login of a user of the group read works, but the router closes the connection at its first SFTP request
     * (no write access to the file storage): a lost connection, not a refused request.
     */
    public function testUserWithoutWriteAccessGetsNoSftp(): void
    {
        $files = $this->files('nopass', '');
        self::assertTrue($files->isOpen());

        try {
            $files->download(self::FILE_PREFIX . 'file.bin');
            self::fail('ConnectionException expected');
        } catch (ConnectionException) {
            self::assertFalse($files->isOpen());
        }
    }

    /**
     * RouterOS 7.16.2 and 7.17.2 refresh the `/file` menu asynchronously after an SFTP upload (~100-500 ms on TCG,
     * while SFTP `download` and `stat` see the file at 0 ms; probe fd-ssh-probe-7.16.2.txt).
     *
     * @return list<array<string, string>>
     */
    private function storedFile(string $remote, string $size): array
    {
        $router = $this->router();
        try {
            $stored = [];
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $stored = $router->rows('/file/print', ['?name=' . $remote, '=.proplist=name,size']);
                if (($stored[0]['size'] ?? null) === $size) {
                    return $stored;
                }
                usleep(100_000);
            }

            return $stored;
        } finally {
            $router->close();
        }
    }

    private function localFile(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'lossik-live-');
        file_put_contents($path, $content);

        return $this->localFiles[] = $path;
    }
}
