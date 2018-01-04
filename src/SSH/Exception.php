<?php
/**
 * Created by PhpStorm.
 * User: Losse
 * Date: 21.06.2017
 * Time: 12:10
 */

namespace Lossik\Device\Mikrotik\SSH;

const SSH_IMPOSSIBLE_CONNECT = 1;

class Exception extends \Exception
{

	public function setMessage($message)
	{
		$this->message = $message;

		return $this;
	}


	public function setCode($code)
	{
		$this->code = $code;

		return $this;
	}

}