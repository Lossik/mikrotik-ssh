<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh;

use Lossik\DeviceCommunication\AuthenticationException;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\DeviceCommunication\TrapException;
use phpseclib3\Net\SFTP;

/**
 * File transfer to and from the device over SFTP.
 *
 *     $files = FileTransfer::connect('10.0.0.1', 'admin', 'secret');
 *     $files->upload('/local/backup.rsc', 'backup.rsc');
 *     $content = $files->download('backup.rsc');
 *
 * A request the device refuses (no such file, no permission) is a TrapException and the session stays open. Any
 * ConnectionException closes the session; every further call then throws ConnectionException without I/O.
 */
final class FileTransfer
{
    private ?SFTP $sftp;

    private function __construct(SFTP $sftp)
    {
        $this->sftp = $sftp;
    }

    /**
     * Opens the connection and logs in; the password is always sent, an empty one too. phpseclib tries the `none`
     * method first, so an account with an empty password logs in whatever password is given.
     *
     * @throws TimeoutException the SSH banner, handshake or login did not finish within connectTimeout
     * @throws ConnectionException the device is unreachable or closed the connection
     * @throws AuthenticationException wrong user name or password
     */
    public static function connect(
        string $host,
        string $user,
        string $password,
        SshOptions $options = new SshOptions(),
    ): self {
        return new self(Connector::open(SFTP::class, $host, $user, $password, $options));
    }

    /**
     * @throws \InvalidArgumentException $local is not a readable file (nothing is sent)
     * @throws TrapException the device refused the upload
     * @throws ConnectionException the session is closed, or got closed by this call (a lost connection, a time
     *                             limit: TimeoutException 'responseTimeout')
     */
    public function upload(string $local, string $remote): void
    {
        $sftp = $this->open();
        if (!is_file($local) || !is_readable($local)) {
            throw new \InvalidArgumentException(sprintf('%s is not a readable file', $local));
        }
        try {
            $done = $sftp->put($remote, $local, SFTP::SOURCE_LOCAL_FILE);
        } catch (\Exception $e) {
            $this->close();
            throw Connector::lost($e, 'The upload');
        }
        if (!$done) {
            throw $this->failure($sftp, sprintf('Upload to %s failed', $remote));
        }
    }

    /**
     * @param string|null $local a file to write to; null = return the content
     * @return string|true the content of the remote file, or true when it was written to $local
     * @throws TrapException the device refused the download (no such file, no permission)
     * @throws ConnectionException the session is closed, or got closed by this call (a lost connection, a time
     *                             limit: TimeoutException 'responseTimeout')
     */
    public function download(string $remote, ?string $local = null): string|true
    {
        $sftp = $this->open();
        try {
            $result = $sftp->get($remote, $local ?? false);
        } catch (\Exception $e) {
            $this->close();
            throw Connector::lost($e, 'The download');
        }
        if ($result === false) {
            throw $this->failure($sftp, sprintf('Download of %s failed', $remote));
        }

        return $result;
    }

    public function close(): void
    {
        $sftp = $this->sftp;
        $this->sftp = null;
        $sftp?->disconnect();
    }

    public function isOpen(): bool
    {
        return $this->sftp !== null && $this->sftp->isConnected();
    }

    private function open(): SFTP
    {
        return $this->sftp ?? throw new ConnectionException('The session is closed');
    }

    private function failure(SFTP $sftp, string $message): ConnectionException|TrapException
    {
        if ($sftp->isTimeout()) {
            $this->close();

            return new TimeoutException($message . ': no reply in time', 'responseTimeout');
        }
        if (!$sftp->isConnected()) {
            $this->close();

            return new ConnectionException($message . ': the connection is lost');
        }

        return new TrapException(sprintf('%s: %s', $message, $sftp->getLastSFTPError() ?: 'refused by the device'));
    }
}
