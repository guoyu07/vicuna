<?php

namespace Vicuna;

use Swoole\Http\Request as RawRequest;

class Request
{
    protected $rawRequest;
    protected $startTime;
    protected $uniqueId;
    protected $trustyProxyIps = [];
    protected $serverInfo;
    protected $username;
    protected $password;
    protected $closed = false;

    public function __construct(RawRequest $rawRequest)
    {
        $this->rawRequest = $rawRequest;
        $this->startTime = microtime(true);
    }

    public function rawRequest()
    {
        return $this->rawRequest;
    }

    public function fd()
    {
        return $this->rawRequest->fd;
    }

    public function startTime()
    {
        return $this->startTime;
    }

    public function executeTime()
    {
        return microtime(true) - $this->startTime;
    }

    public function uniqueId()
    {
        if (! $this->id) {
            $id = strtoupper(md5(uniqid().microtime().rand()));
            $this->id = substr($id, 0, 8).
                '-'.substr($id, 8, 4).
                '-'.substr($id, 12, 4).
                '-'.substr($id, 16, 4).
                '-'.substr($id, 20, 12);
        }

        return $this->id;
    }

    protected function fetchValue($property, $key, $default = null)
    {
        if ($key === null) {
            return isset($this->rawRequest->$property) ? $this->rawRequest->$property : [];
        } else {
            $key = strtolower($key);
            return isset($this->rawRequest->$property) && isset($this->rawRequest->{$property}[$key]) ?
                $this->rawRequest->{$property}[$key] : $default;
        }
    }

    public function header($key = null, $default = null)
    {
        return $this->fetchValue('header', $key, $default);
    }

    protected function parseAuthorization()
    {
        if ($this->username === null) {
            $this->username = '';
            $this->password = '';

            $auth = $this->header('authorization');
            if (substr($auth, 0, 6) == 'Basic ') {
                $auth = base64_decode(substr($auth, 6));
                $temp = explode(':', $auth);
                if (count($temp) == 2) {
                    $this->username = $temp[0];
                    $this->password = $temp[1];
                }
            }
        }
    }

    public function username($parseFromQuery = false)
    {
        $this->parseAuthorization();
        if ($parseFromQuery) {
            return $this->username ?: (string) $this->get('_auth_user');
        } else {
            return $this->username;
        }
    }

    public function password($parseFromQuery = false)
    {
        $this->parseAuthorization();
        if ($parseFromQuery) {
            return $this->password ?: (string) $this->get('_auth_pass');
        } else {
            return $this->password;
        }
    }

    public function server($key = null, $default = null)
    {
        if (is_array($this->serverInfo)) {
            if ($key === null) {
                return $this->serverInfo;
            } else {
                $key = strtoupper($key);
                return isset($this->serverInfo[$key]) ? $this->serverInfo[$key] : $default;
            }
        } else {
            if ($key === null) {
                $this->serverInfo = [];
                foreach ($this->fetchValue('header', null) as $k => $v) {
                    $this->serverInfo['HTTP_'.str_replace('-', '_', strtoupper($k))] = $v;
                }
                foreach ($this->fetchValue('server', null) as $k => $v) {
                    $this->serverInfo[strtoupper($k)] = $v;
                }

                $this->serverInfo['PHP_AUTH_USER'] = $this->username();
                $this->serverInfo['PHP_AUTH_PW'] = $this->password();
                return $this->serverInfo;
            } else {
                if (strtoupper(substr($key, 0, 5)) == 'HTTP_') {
                    if (! is_array($this->serverInfo)) {
                        $this->serverInfo();
                    }
                    return $this->serverInfo(substr($key, 5), $default);
                } elseif (strtoupper($key) == 'PHP_AUTH_USER') {
                    return $this->username();
                } elseif (strtoupper($key) == 'PHP_AUTH_PW') {
                    return $this->password();
                } else {
                    return $this->fetchValue('server', $key, $default);
                }
            }
        }
    }

    public function get($key = null, $default = null)
    {
        return $this->fetchValue('get', $key, $default);
    }

    public function post($key = null, $default = null)
    {
        return $this->fetchValue('post', $key, $default);
    }

    public function input($key = null, $default = null)
    {
        if ($key === null) {
            return array_merge($this->get(), $this->post());
        } else {
            $input = $this->post($key);
            if ($input === null) {
                $input = $this->get($key);
            }
            if ($input === null) {
                return $default;
            } else {
                return $input;
            }
        }
    }

    public function cookie($key = null, $default = null)
    {
        return $this->fetchValue('cookie', $key, $default);
    }

    public function files($key = null, $default = null)
    {
        return $this->fetchValue('files', $key, $default);
    }

    public function rawContent()
    {
        return $this->rawRequest->rawContent();
    }

    public function method()
    {
        return $this->server('request_method', 'GET');
    }

    public function path()
    {
        return $this->server('request_uri', '/');
    }

    public function uri()
    {
        $uri = $this->path();
        $queryString = $this->server('query_string', '');
        if (strlen($queryString)) {
            return $uri.'?'.$queryString;
        } else {
            return $uri;
        }
    }

    public function setTrustyProxyIps($ips)
    {
        $this->trustyProxyIps = (array) $ips;
    }

    public function clientIp()
    {
        $clientIp = $this->server('remote_addr');
        $realIp = $this->header('x-real-ip');
        if ($realIp && in_array($realIp, $this->trustyProxyIps)) {
            $clientIp = $realIp;
        }

        return $clientIp;
    }

    public function isHttp2()
    {
        // todo
    }

    public function isWebSocket()
    {
        return strtolower($this->header('upgrade')) == 'websocket';
    }

    public function setClosed()
    {
        $this->closed = true;
    }

    public function isClosed()
    {
        return $this->closed;
    }
}
