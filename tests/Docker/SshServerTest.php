<?php declare(strict_types = 1);

namespace Lossik\Device\Mikrotik\Tests\Docker;

use Closure;
use Lossik\Device\Communication\RuntimeException;
use Lossik\Device\Mikrotik\SFTP;
use Lossik\Device\Mikrotik\SSH;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The 2.x SSH and SFTP clients against OpenSSH started by tools/sshd/start.sh in the same container.
 * The settings come from .cache/sshd/env; a missing file is a failure, not a skip: a docker run that tests nothing
 * must not look green.
 */
#[Group('docker')]
final class SshServerTest extends TestCase
{

	private const ENV_FILE = __DIR__ . '/../../.cache/sshd/env';

	/** @var array{SSHD_HOST: string, SSHD_PORT: string, SSHD_USER: string, SSHD_PASS: string} */
	private array $sshd;


	protected function setUp(): void
	{
		$this->sshd = self::sshd();
	}


	public function testCommandRunsOnTheServer(): void
	{
		$connection = (new SSH\Connection($this->options((int) $this->sshd['SSHD_PORT'])))
			->connect($this->sshd['SSHD_HOST'], $this->sshd['SSHD_USER'], $this->sshd['SSHD_PASS']);

		self::assertSame("hello\nLinux\n", $connection->comm('echo hello; uname -s'));
	}


	public function testUploadedFileIsDownloadedBackAsContent(): void
	{
		$content = 'payload-' . bin2hex(random_bytes(8));
		$remote  = 'upload-' . bin2hex(random_bytes(4)) . '.txt';
		$local   = tempnam(sys_get_temp_dir(), 'sftp');
		file_put_contents($local, $content);

		try {
			$connection = (new SFTP\Connection($this->options((int) $this->sshd['SSHD_PORT'])))
				->connect($this->sshd['SSHD_HOST'], $this->sshd['SSHD_USER'], $this->sshd['SSHD_PASS']);
			$connection->uploadfile($local, $remote);

			self::assertSame($content, $connection->downloadfile($remote));
		} finally {
			unlink($local);
		}
	}


	/**
	 * Every way a connection can fail ends in the same exception (code 2) - that is what callers of 2.x catch today.
	 *
	 * @return array<string, array{Closure(array<string, string>): array{int, string, mixed}}>
	 */
	public static function failedConnections(): array
	{
		return [
			'nothing listens on the port' => [static function (array $sshd): array {
				$server = stream_socket_server('tcp://127.0.0.1:0');
				$port   = self::port($server);
				fclose($server);

				return [$port, $sshd['SSHD_PASS'], null];
			}],
			'wrong password' => [static fn (array $sshd): array => [(int) $sshd['SSHD_PORT'], bin2hex(random_bytes(12)), null]],
			'server accepts TCP but never sends an SSH banner' => [static function (array $sshd): array {
				// never accepted: the kernel completes the TCP handshake from the backlog, nothing is ever sent
				$server = stream_socket_server('tcp://127.0.0.1:0');

				return [self::port($server), $sshd['SSHD_PASS'], $server];
			}],
		];
	}


	/**
	 * @param Closure(array<string, string>): array{int, string, mixed} $target
	 */
	#[DataProvider('failedConnections')]
	public function testFailedConnectionThrowsLoginImpossible(Closure $target): void
	{
		[$port, $password, $keepOpen] = $target($this->sshd);

		try {
			(new SSH\Connection($this->options($port)))->connect('127.0.0.1', $this->sshd['SSHD_USER'], $password);
			self::fail('connect() did not throw');
		} catch (RuntimeException $e) {
			self::assertSame(2, $e->getCode());
		} finally {
			if (is_resource($keepOpen)) {
				fclose($keepOpen);
			}
		}
	}


	private function options(int $port): SSH\Options
	{
		$options       = new SSH\Options();
		$options->port = $port;

		return $options;
	}


	/**
	 * @param resource $server
	 */
	private static function port($server): int
	{
		$name = (string) stream_socket_get_name($server, false);

		return (int) substr($name, strrpos($name, ':') + 1);
	}


	/**
	 * @return array{SSHD_HOST: string, SSHD_PORT: string, SSHD_USER: string, SSHD_PASS: string}
	 */
	private static function sshd(): array
	{
		$settings = is_file(self::ENV_FILE) ? parse_ini_file(self::ENV_FILE, false, INI_SCANNER_RAW) : false;
		foreach (['SSHD_HOST', 'SSHD_PORT', 'SSHD_USER', 'SSHD_PASS'] as $name) {
			if (!is_array($settings) || ($settings[$name] ?? '') === '') {
				self::fail("$name is missing in .cache/sshd/env; run bash tools/sshd/start.sh in the container first");
			}
		}

		return $settings;
	}

}
