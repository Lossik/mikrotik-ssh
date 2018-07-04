<?php declare(strict_types=1);


namespace Lossik\Device\Mikrotik\SFTP;

use Lossik\Device\Mikrotik\SSH;
use phpseclib\Net\SFTP;

class Definition extends SSH\Definition
{


	public function socket($options, $ip)
	{
		return new SFTP($ip, $options->port);
	}


}