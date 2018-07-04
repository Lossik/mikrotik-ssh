<?php declare(strict_types=1);

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