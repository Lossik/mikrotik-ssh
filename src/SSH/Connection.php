<?php declare(strict_types=1);

namespace Lossik\Device\Mikrotik\SSH;

use Lossik\Device\Communication\ICommand;
use phpseclib\Net\SSH2;

/**
 * @property SSH2 $socket
 */
class Connection extends \Lossik\Device\Communication\Connection
{


	public function __construct(Options $options = null)
	{
		$options = $options ?: new Options();
		parent::__construct($options, new Definition());
	}


	public function disconnect()
	{
		if ($this->socket) {
			$this->socket->disconnect();
			$this->socket = null;
		}
	}


	public function isConnected()
	{
		return $this->hasSocket()?$this->socket->isConnected():false;
	}

	public function hasSocket()
	{
		return (bool) $this->socket;
	}

	/**
	 * @param string $menu
	 * @return ICommand
	 */
	public function Command($menu): ICommand
	{
		$command = new Command($menu);
		$command->setConnection($this);

		return $command;
	}

}