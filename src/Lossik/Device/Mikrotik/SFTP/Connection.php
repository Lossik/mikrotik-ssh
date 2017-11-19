<?php

namespace Lossik\Device\Mikrotik\SFTP;

use Lossik\Device\Mikrotik\SSH;

class Connection
{

	/** @var  SSH\Connection */
	protected $connection;


	/**
	 * @param SSH\Connection $connection
	 *
	 */
	public function __construct(SSH\Connection $connection)
	{
		$this->connection = $connection;
	}


	function uploadfile($local, $remote)
	{
		$sftp = ssh2_sftp($this->connection->getSocket());
		if (!is_resource($sftp)) {
			throw new SSH\Exception('Nelze vytvorit spojeni pres SFTP');
		}
		$dstFile = fopen("ssh2.sftp://{$sftp}/" . $remote, 'w');
		$srcFile = fopen($local, 'r');
		stream_copy_to_stream($srcFile, $dstFile);
		fclose($dstFile);
		fclose($srcFile);
	}


	function downloadfile($remote, $local = null)
	{
		$sftp = ssh2_sftp($this->connection->getSocket());
		if (!is_resource($sftp)) {
			throw new SSH\Exception('Nelze vytvorit spojeni pres SFTP');
		}
		$stream = fopen("ssh2.sftp://{$sftp}/" . $remote, 'r');
		stream_set_blocking($stream, true);
		$contents = stream_get_contents($stream);
		if ($local) {
			file_put_contents($local, $contents);
			fclose($stream);

			return $local;
		}
		else {
			return $contents;
		}
	}

}