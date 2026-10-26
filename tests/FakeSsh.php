<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests;

use phpseclib3\Net\SSH2;

final class FakeSsh extends SSH2
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<string|false> */
    public array $outputs;

    public bool $fakeTimeout = false;

    public bool $connected = true;

    /** @param list<string|false> $outputs */
    public function __construct(array $outputs)
    {
        $this->outputs = $outputs;
    }

    public function exec($command, $callback = null)
    {
        $this->commands[] = $command;
        return array_shift($this->outputs) ?? false;
    }

    public function isTimeout(): bool
    {
        return $this->fakeTimeout;
    }

    public function isConnected($level = 0)
    {
        return $this->connected;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }
}
