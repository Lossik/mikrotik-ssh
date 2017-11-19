<?php
/**
 * Created by PhpStorm.
 * User: Losse
 * Date: 21.06.2017
 * Time: 12:10
 */

namespace Lossik\Device\Mikrotik\SSH;


class Exception extends \Exception
{


	const NO_CONNECTION = 1;
	const NO_LOGIN      = 2;


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