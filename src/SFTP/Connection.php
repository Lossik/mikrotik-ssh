<?php declare(strict_types=1);

namespace Lossik\Device\Mikrotik\SFTP;

use Lossik\Device\Mikrotik\SSH;
use phpseclib\Net\SFTP;

/**
 * @property SFTP $socket
 */
class Connection extends SSH\Connection
{


	public function __construct(Options $options = null)
	{
		$this->options    = $options ?? new SSH\Options();
		$this->definition = new Definition();
	}


	public function uploadfile($local, $remote){
		$this->socket->put($remote, $local, SFTP::SOURCE_LOCAL_FILE);
	}


	function downloadfile($remote, $local = false)
	{
		return $this->socket->get($remote, $local);
	}

}