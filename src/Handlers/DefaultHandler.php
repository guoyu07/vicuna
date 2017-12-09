<?php

namespace Vicuna\Handlers;

use Vicuna\Handler;

class DefaultHandler extends Handler
{
    public function onRequest($server, $request, $response)
    {
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->send($request->server());

        $green = "\e[32m";
        $close = "\e[0m";

        $server->log('DEBUG', sprintf(
            '[ %s%d%s ] %0.6f | %s %s',
            $green,
            $response->getStatusCode(),
            $close,
            $request->executeTime(),
            $request->method(),
            $request->uri()
        ));
    }
}
