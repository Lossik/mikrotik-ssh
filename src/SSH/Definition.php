<?php declare(strict_types=1);


namespace Lossik\Device\Mikrotik\SSH;


use Lossik\Device\Communication\IDefinition;
use phpseclib\Net\SSH2;

class Definition implements IDefinition
{


	/**
	 * @param SSH2 $socket
	 * @param $login
	 * @param $password
	 * @return bool
	 */
	public function login($socket, $login, $password)
	{
		if ($password) {
			$loget = $socket->login($login, $password) === true;
		}
		else {
			$loget = $socket->login($login) === true;
		}

		return $loget;
	}


	/**
	 * @param SSH2 $socket
	 * @param $com
	 * @param array $arr
	 * @return mixed
	 */
	public function comm($socket, $com, $arr = [])
	{
		$result = '';
		$socket->exec($com, function ($out) use (& $result){
			$result .= $out;
		});

		return $result;
	}


	public function socket($options, $ip)
	{
		$socket = new SSH2($ip, $options->port, 3);

		return $socket;
	}


}