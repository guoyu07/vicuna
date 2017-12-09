<?php

namespace Vicuna;

abstract class Task
{
    use OptionsTrait;

    protected $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    abstract public function run();
}
