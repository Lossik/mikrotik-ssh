<?php


namespace Lossik\Device\Mikrotik\SSH;


use Lossik\Device\Communication\IDefinition;

class Definition implements IDefinition
{


	public function login($socket, $login, $password)
	{
		if ($password) {
			$loget = @ssh2_auth_password($socket, $login, $password) === true;
		}
		else {
			$loget = @ssh2_auth_none($socket, $login) === true;
		}

		return $loget;
	}


	public function comm($socket, $com, $arr = [])
	{

		$stream = $this->write($socket, $com);
		$return = $this->read($stream);

		return $return;
	}


	protected function write($socket, $command, $param2 = true)
	{

		$stream = ssh2_exec($socket, $command);
		stream_set_blocking($stream, true);

		return $stream;
	}


	protected function read($socket, $loged = true, $parse = true)
	{
		return stream_get_contents($socket);
	}


	public function socket($options, $ip)
	{
		$socket = ssh2_connect($ip, $options->port);
		if (!is_resource($socket)) {
			throw new Exception('Cant create connection. ' . $ip, SSH_IMPOSSIBLE_CONNECT);
		}

		return $socket;
	}


}