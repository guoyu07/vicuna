<?php

namespace Vicuna;

abstract class Handler
{
    use OptionsTrait;

    protected $path;

    public function setPath($path)
    {
        $this->path = $path;
    }

    public function getPath()
    {
        $path = trim($this->path);
        if (strlen($path)) {
            if (substr($path, 0, 1) != '/') {
                $path = '/'.$path;
            }
            return $path;
        } else {
            return '/';
        }
    }

    public function onInit($server)
    {
        // pass
    }

    public function onStart($server)
    {
        // pass
    }

    public function onShutdown($server)
    {
        // pass
    }

    public function onWorkerStart($server)
    {
        // pass
    }

    public function onWorkerStop($server)
    {
        // pass
    }

    public function onOpen($server, $request)
    {
        $server->close($request->fd);
    }

    public function onClose($server, $fd)
    {
        // pass
    }

    public function onMessage($server, $frame)
    {
        // pass
    }

    public function onRequest($server, $request, $response)
    {
        $response->error(404);
    }
}
