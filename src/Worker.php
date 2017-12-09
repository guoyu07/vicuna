<?php

namespace Vicuna;

use Swoole\Process;

abstract class Worker
{
    use OptionsTrait;

    protected $name;
    protected $server;
    protected $process;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return trim($this->name);
    }

    public function setProcess(Process $process)
    {
        $this->process = $process;
    }

    public function getProcess()
    {
        return $this->process;
    }

    public function init()
    {
        // pass
    }

    abstract public function run();
}
