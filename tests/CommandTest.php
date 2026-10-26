<?php declare(strict_types = 1);

namespace Lossik\Device\Mikrotik\Tests;

use Lossik\Device\Communication\ICommand;
use Lossik\Device\Mikrotik\SSH;
use PHPUnit\Framework\TestCase;

/**
 * Needs no server: it only loads SSH\Command, which extends a class of lossik/device-communication that the lowest
 * version allowed by composer.json must contain (the "lowest" CI job installs exactly that version).
 */
final class CommandTest extends TestCase
{

	public function testConnectionBuildsACommand(): void
	{
		self::assertInstanceOf(ICommand::class, (new SSH\Connection())->Command('/ip/address'));
	}

}
