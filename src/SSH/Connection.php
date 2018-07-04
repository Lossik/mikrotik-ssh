<?php declare(strict_types=1);

namespace Lossik\Device\Mikrotik\SSH;


class Connection extends \Lossik\Device\Communication\Connection
{


	protected $shell;


	public function __construct(Options $options = null)
	{
		$options = $options ?: new Options();
		parent::__construct($options, new Definition());
	}


	public function getSocket()
	{
		return $this->socket;
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
		return $this->socket->isConnected();
	}


}