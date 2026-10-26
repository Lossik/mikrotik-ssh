<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh;

/**
 * Connection options of SshTransport and FileTransfer. All times are in seconds.
 */
final class SshOptions
{
    public const PORT = 22;

    /**
     * @param int $port the SSH port
     * @param float $connectTimeout one budget for opening the TCP connection and receiving the SSH banner together;
     *                              the key exchange and the login then get their own limit of ceil(connectTimeout)
     * @param float|null $responseTimeout how long to wait for the output of each console command the transport runs
     *                                    (SshTransport), or for the reply to one SFTP request (FileTransfer); null
     *                                    = no limit. One execute() may run several console commands (the version
     *                                    query once per connection, the print, one retry without the proplist, one
     *                                    query per missing flag), each with the full limit, so it is not a limit of
     *                                    the whole execute(). 60 s by default: the RouterOS CLI answers within a
     *                                    fraction of a second, so the limit only guards against a device that went
     *                                    silent; raise it for commands that run long
     * @param string $charset SshTransport only: the charset of the text on the device. 'UTF-8' passes the bytes
     *                        unchanged; any other ASCII-compatible single-byte charset (e.g. 'windows-1250', the
     *                        default of 2.x) converts every value of a command and the whole output of it with iconv,
     *                        like ApiOptions::charset. FileTransfer ignores it: file names and contents are bytes
     */
    public function __construct(
        public readonly int $port = self::PORT,
        public readonly float $connectTimeout = 10.0,
        public readonly ?float $responseTimeout = 60.0,
        public readonly string $charset = 'UTF-8',
    ) {
    }
}
