<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Docker;

use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\TrapException;
use Lossik\Mikrotik\Ssh\FileTransfer;
use Lossik\Mikrotik\Ssh\SshOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * FileTransfer against the OpenSSH of tools/sshd/start.sh (the SFTP subsystem of the distribution config).
 */
#[Group('docker')]
#[Medium]
final class FileTransferTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * S6: guard. 2.x passed; `download` without a local file returns the content.
     */
    public function testUploadedFileIsDownloadedBackAsContent(): void
    {
        $content = "payload-\0-" . bin2hex(random_bytes(8));
        $remote = 'upload-' . bin2hex(random_bytes(4)) . '.txt';
        $files = self::connect();

        $files->upload($this->localFile($content), $remote);

        self::assertSame($content, $files->download($remote));
    }

    public function testDownloadToALocalFileReturnsTrueAndWritesTheFile(): void
    {
        $content = 'payload-' . bin2hex(random_bytes(8));
        $remote = 'upload-' . bin2hex(random_bytes(4)) . '.txt';
        $files = self::connect();
        $files->upload($this->localFile($content), $remote);
        $target = $this->localFile('');

        self::assertTrue($files->download($remote, $target));
        self::assertSame($content, file_get_contents($target));
    }

    public function testMissingRemoteFileIsATrapAndTheSessionStaysOpen(): void
    {
        $files = self::connect();

        try {
            $files->download('no-such-file-' . bin2hex(random_bytes(4)));
            self::fail('TrapException expected');
        } catch (TrapException) {
            self::assertTrue($files->isOpen());
        }
    }

    public function testMissingLocalFileIsRefusedBeforeAnythingIsSent(): void
    {
        $files = self::connect();

        $this->expectException(\InvalidArgumentException::class);
        $files->upload('/no/such/local/file', 'x.txt');
    }

    public function testTransferAfterCloseIsAConnectionError(): void
    {
        $files = self::connect();
        $files->close();

        $this->expectException(ConnectionException::class);
        $files->download('x.txt');
    }

    private function localFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sftp');
        self::assertNotFalse($path);
        $this->cleanup[] = $path;
        file_put_contents($path, $content);

        return $path;
    }

    private static function connect(): FileTransfer
    {
        $sshd = Sshd::settings();

        return FileTransfer::connect(
            $sshd['SSHD_HOST'],
            $sshd['SSHD_USER'],
            $sshd['SSHD_PASS'],
            new SshOptions(port: (int) $sshd['SSHD_PORT']),
        );
    }
}
