<?php

namespace Lossik\Device\Mikrotik\SSH;

use Lossik\Device\Communication\Connection as CommConnection;

class Connection extends CommConnection
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
		if (is_resource($this->socket)) {
			unset($this->socket);
			$this->socket = null;
		}
	}

}