<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh;

use Lossik\DeviceCommunication\AuthenticationException;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\TimeoutException;
use phpseclib3\Exception\TimeoutException as PhpseclibTimeoutException;
use phpseclib3\Net\SSH2;

/**
 * Opens and logs in an SSH2 (or SFTP) session; shared by SshTransport and FileTransfer.
 *
 * phpseclib is given a socket that is already open and has sent its SSH banner: its own connect() waits for the
 * banner in a blocking read that ignores the timeout, so a server that accepts the connection and stays silent would
 * hold it for default_socket_timeout (60 s).
 *
 * @internal
 */
final class Connector
{
    /**
     * @template T of SSH2
     * @param class-string<T> $class SSH2 or a subclass (SFTP)
     * @return T logged in, with the timeout of a response set
     * @throws TimeoutException a time limit ran out ('connectTimeout')
     * @throws ConnectionException the device is unreachable or closed the connection, or the SSH handshake failed
     * @throws AuthenticationException the device rejected the user name or password
     */
    public static function open(
        string $class,
        string $host,
        string $user,
        string $password,
        SshOptions $options,
    ): SSH2 {
        $address = sprintf(
            'tcp://%s:%d',
            str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host,
            $options->port,
        );
        $started = hrtime(true);
        $stream = @stream_socket_client($address, $errno, $errstr, $options->connectTimeout);
        if ($stream === false) {
            $message = sprintf('Cannot connect to %s: %s (%d)', $address, $errstr, $errno);
            if (self::elapsed($started) >= $options->connectTimeout) {
                throw new TimeoutException($message, 'connectTimeout');
            }
            throw new ConnectionException($message);
        }

        try {
            self::waitForBanner($stream, $address, $started, $options->connectTimeout);
            $ssh = new $class($stream, $options->port, max(1, (int) ceil($options->connectTimeout)));
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }

        try {
            $loggedIn = $ssh->login($user, $password);
        } catch (\Exception $e) {
            $ssh->disconnect();
            $message = sprintf('SSH handshake with %s failed: %s', $address, $e->getMessage());
            if (self::elapsed($started) >= $options->connectTimeout) {
                throw new TimeoutException($message, 'connectTimeout', $e);
            }
            throw new ConnectionException($message, 0, $e);
        }
        if (!$loggedIn) {
            $ssh->disconnect();
            throw new AuthenticationException(sprintf('%s rejected the user name or password', $address));
        }
        $ssh->setTimeout($options->responseTimeout ?? 0);

        return $ssh;
    }

    /**
     * The exception for a session that failed while a request was running (the caller closes it).
     */
    public static function lost(\Exception $e, string $what): ConnectionException
    {
        $message = sprintf('%s failed: %s', $what, $e->getMessage());
        if ($e instanceof PhpseclibTimeoutException) {
            return new TimeoutException($message, 'responseTimeout', $e);
        }

        return new ConnectionException($message, 0, $e);
    }

    /**
     * @param resource $stream
     */
    private static function waitForBanner($stream, string $address, int $started, float $connectTimeout): void
    {
        $remaining = $connectTimeout - self::elapsed($started);
        $ready = 0;
        if ($remaining > 0) {
            $read = [$stream];
            $write = $except = null;
            $seconds = (int) floor($remaining);
            $ready = @stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000));
        }
        if ($ready === false) {
            throw new ConnectionException(sprintf('Waiting for the SSH banner of %s failed', $address));
        }
        if ($ready === 0) {
            throw new TimeoutException(sprintf('%s sent no SSH banner', $address), 'connectTimeout');
        }
        // backstop for a banner that stops in the middle of a line
        stream_set_timeout($stream, max(1, (int) ceil($connectTimeout)));
    }

    private static function elapsed(int $started): float
    {
        return (hrtime(true) - $started) / 1e9;
    }
}
