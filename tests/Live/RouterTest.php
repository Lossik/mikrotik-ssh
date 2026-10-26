<?php declare(strict_types = 1);

namespace Lossik\Device\Mikrotik\Tests\Live;

use Lossik\Device\Communication\RuntimeException;
use Lossik\Device\Mikrotik\SSH\Connection;
use Lossik\Device\Mikrotik\SSH\Options;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The 2.x SSH command API against a real RouterOS (MikroTik CHR booted by tools/chr/run-live.sh, which exports
 * MIKROTIK_LIVE_*). Missing settings are a failure, not a skip: a live run that tests nothing must not look green.
 */
#[Group('live')]
final class RouterTest extends TestCase
{

	private const MARK = 'lossik-live-test-ssh';

	private const MENU = '/ip/firewall/address-list';

	private Connection $connection;


	protected function setUp(): void
	{
		$env = [];
		foreach (['MIKROTIK_LIVE_HOST', 'MIKROTIK_LIVE_PORT_SSH', 'MIKROTIK_LIVE_USER', 'MIKROTIK_LIVE_PASS'] as $name) {
			$value = getenv($name);
			if ($value === false || $value === '') {
				self::fail("$name is not set; run the live tests through tools/chr/run-live.sh");
			}
			$env[$name] = $value;
		}

		$options       = new Options();
		$options->port = (int) $env['MIKROTIK_LIVE_PORT_SSH'];

		$this->connection = $this->connectWithRetry($options, $env['MIKROTIK_LIVE_HOST'], $env['MIKROTIK_LIVE_USER'], $env['MIKROTIK_LIVE_PASS']);
		$this->removeTestEntries();
	}


	/**
	 * A freshly booted CHR sends its SSH banner before password logins work (seen on a slow TCG runner), so the first
	 * connect is retried for up to 30 s. 2.x reports an unreachable router and a rejected login alike (RuntimeException,
	 * code 2), so the retry cannot be narrower: a wrong MIKROTIK_LIVE_PASS fails only after the deadline.
	 */
	private function connectWithRetry(Options $options, string $host, string $user, string $pass): Connection
	{
		$deadline = microtime(true) + 30;
		for ($attempt = 1; ; $attempt++) {
			try {
				return (new Connection($options))->connect($host, $user, $pass);
			} catch (RuntimeException $e) {
				if (microtime(true) + 2 > $deadline) {
					throw $e;
				}
				fwrite(STDERR, sprintf("RouterTest: connect attempt %d failed (%s), retrying in 2 s\n", $attempt, $e->getMessage()));
				sleep(2);
			}
		}
	}


	protected function tearDown(): void
	{
		if (isset($this->connection) && $this->connection->isConnected()) {
			$this->removeTestEntries();
			$this->connection->disconnect();
		}
	}


	private function removeTestEntries(): void
	{
		if ($this->testEntries()) {
			$this->connection->Command(self::MENU)->del(['list' => self::MARK]);
		}
	}


	/**
	 * Only items with an ID: 2.x get() returns [["\r"]] for an empty result (known issue, see CHANGELOG).
	 *
	 * @return list<array<string, string>>
	 */
	private function testEntries(): array
	{
		return array_values(array_filter(
			$this->connection->Command(self::MENU)->get(['list' => self::MARK]),
			static fn (array $item): bool => isset($item['.id']),
		));
	}


	public function testSystemResourceReportsTheRouterVersion(): void
	{
		$resource = $this->connection->Command('/system/resource')->get();

		self::assertCount(1, $resource);
		$expected = getenv('CHR_VERSION');
		self::assertStringStartsWith($expected !== false && $expected !== '' ? $expected : '7.', $resource[0]['version'] ?? '');
	}


	public function testAddressListEntryCanBeAddedFoundAndDeleted(): void
	{
		$list    = $this->connection->Command(self::MENU);
		$address = '192.0.2.' . random_int(1, 254);

		$list->add(['list' => self::MARK, 'address' => $address, 'comment' => self::MARK]);

		$entries = $this->testEntries();
		self::assertCount(1, $entries);
		self::assertSame($address, $entries[0]['address'] ?? null);
		self::assertSame(self::MARK, $entries[0]['comment'] ?? null);

		$list->del(['list' => self::MARK]);

		self::assertSame([], $this->testEntries());
	}

}
