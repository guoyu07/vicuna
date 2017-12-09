<?php

namespace Vicuna;

use Swoole\Http\Response as RawResponse;

class Response
{
    protected $rawResponse;
    protected $output = false;
    protected $statusCode = 200;
    protected $body = '';

    public function __construct(RawResponse $rawResponse)
    {
        $this->rawResponse = $rawResponse;
    }

    public function rawResponse()
    {
        return $this->rawResponse;
    }

    public function hasOutput()
    {
        return $this->output;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function header($key, $value)
    {
        $this->rawResponse->header($key, $value);
    }

    public function cookie(
        $key,
        $value = '',
        $expire = 0,
        $path = '/',
        $domain = '',
        $secure = false,
        $httponly = false
    ) {
        $this->rawResponse->cookie($key, $value, $expire, $path, $domain, $secure, $httponly);
    }

    public function status($code)
    {
        $this->statusCode = intval($code);
        $this->rawResponse->status($this->statusCode);
    }

    public function gzip($level)
    {
        $this->rawResponse->gzip($level);
    }

    public function write($data)
    {
        $this->output = true;
        $this->rawResponse->write($data);
    }

    public function sendfile($filename)
    {
        $this->output = true;
        $this->rawResponse->sendfile($filename);
    }

    public function end($data)
    {
        $this->output = true;
        $this->rawResponse->end($data);
    }

    public function chunk($data)
    {
        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->body .= ($data = (string) $data);
        $this->write($data);
    }

    public function send($data)
    {
        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->body .= ($data = (string) $data);
        $this->end($data);
    }

    public function error($code)
    {
        $this->status($code);
        $this->header('Connection', 'close');
        $this->end(sprintf(
            '<html><body><h2>HTTP ERROR %d</h2><hr><i>Powered by swoole-http-server (%s)</i></body></html>',
            $code,
            swoole_version()
        ));
    }
}
