<?php declare(strict_types=1);

namespace Lossik\Device\Mikrotik\SFTP;

use Lossik\Device\Communication\ICommand;
use Lossik\Device\Communication\LogicException;
use Lossik\Device\Mikrotik\SSH;
use phpseclib\Net\SFTP;

/**
 * @property SFTP $socket
 */
class Connection extends SSH\Connection
{


	public function __construct(SSH\Options $options = null)
	{
		$this->options    = $options ?? new SSH\Options();
		$this->definition = new Definition();
	}


	public function uploadfile($local, $remote){
		$this->socket->put($remote, $local, SFTP::SOURCE_LOCAL_FILE);
	}


	public function downloadfile($remote, $local = false)
	{
		return $this->socket->get($remote, $local);
	}


	public function Command($menu): ICommand
	{
		throw new LogicException();
	}

}