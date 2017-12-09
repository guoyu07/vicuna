<?php

namespace Vicuna;

use Exception;
use Swoole\Process;
use Swoole\Table;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;
use Vicuna\Handlers\DefaultHandler;

class Server
{
    use OptionsTrait;

    protected $rawServer;
    protected $processFlag = '#';
    protected $handlers = [];
    protected $fds = [];
    protected $workers = [];
    protected $exit = false;
    protected $fdsInfoTable;

    public function __construct(array $options = [])
    {
        if (! extension_loaded('swoole')) {
            throw new Exception('please install swoole extension first');
        }

        $this->options = $options + [
            'host' => '0.0.0.0',
            'port' => 8000,
            'process_title' => 'swoole-http-server',
            'pid_file' => '/tmp/server.pid',
            'log_file' => '/tmp/server.log',
            'daemonize' => false,
            'worker_num' => 4,
            'task_worker_num' => 4,
            'on_boot' => null,
            'on_init' => null,
            'on_start' => null,
            'on_shutdown' => null,
            'on_worker_start' => null,
            'on_worker_stop' => null,
            'handlers' => [DefaultHandler::class],
            'workers' => [],
        ];

        if (is_callable($this->options['on_boot'])) {
            call_user_func($this->options['on_boot'], $this);
        }

        foreach ($this->options['handlers'] as $handlerClass) {
            if (is_array($handlerClass)) {
                if (isset($handlerClass['options'])) {
                    $handlerOptions = (array) $handlerClass['options'];
                } else {
                    $handlerOptions = [];
                }
                if (isset($handlerClass['path'])) {
                    $handlerPath = (string) $handlerClass['path'];
                } else {
                    $handlerPath = '';
                }
                if (isset($handlerClass['class'])) {
                    $handlerClass = (string) $handlerClass['class'];
                } else {
                    $handlerClass = '';
                }
            } else {
                $handlerOptions = [];
                $handlerPath = '';
            }

            if (! class_exists($handlerClass)) {
                throw new Exception("handler($handlerClass) class not found");
            }

            $handler = new $handlerClass();
            if (! $handler instanceof Handler) {
                throw new Exception("handler($handlerClass) class must be a subclass of ".Handler::class);
            }

            $handler->setOptions($handlerOptions);

            $handlerPath && $handler->setPath($handlerPath);
            $handlerPath = $handler->getPath();
            if (! preg_match('#^/(\w+/)*(\w+)?$#', $handlerPath)) {
                throw new Exception("handler($handlerClass) path($handlerPath) invalid");
            }

            $this->handlers[$handlerPath] = $handler;
        }

        foreach ($this->options['workers'] as $workerClass) {
            if (is_array($workerClass)) {
                if (isset($workerClass['options'])) {
                    $workerOptions = (array) $workerClass['options'];
                } else {
                    $workerOptions = [];
                }
                if (isset($workerClass['name'])) {
                    $workerName = (string) $workerClass['name'];
                } else {
                    $workerName = '';
                }
                if (isset($workerClass['class'])) {
                    $workerClass = (string) $workerClass['class'];
                } else {
                    $workerClass = '';
                }
            } else {
                $workerOptions = [];
                $workerName = '';
            }

            if (! class_exists($workerClass)) {
                throw new Exception("worker($workerClass) class not found");
            }

            $worker = new $workerClass($this);
            if (! $worker instanceof Worker) {
                throw new Exception("worker($workerClass) class must be a subclass of ".Worker::class);
            }

            $worker->setOptions($workerOptions);

            $workerName && $worker->setName($workerName);
            $workerName = $worker->getName();
            if (! preg_match('#^[\w+\-\.@/]+$#', $workerName)) {
                throw new Exception("worker($workerClass) name($workerName) invalid");
            }

            $this->workers[$workerName] = $worker;
        }
    }

    public function start()
    {
        if (empty($this->handlers)) {
            echo "no handlers\n";
            exit(1);
        }

        $this->rawServer = new WebSocketServer(
            $this->options['host'],
            $this->options['port']
        );

        $this->rawServer->on('start', [$this, 'onStart']);
        $this->rawServer->on('shutdown', [$this, 'onShutdown']);
        $this->rawServer->on('managerStart', [$this, 'onManagerStart']);
        $this->rawServer->on('managerStop', [$this, 'onManagerStop']);
        $this->rawServer->on('workerStart', [$this, 'onWorkerStart']);
        $this->rawServer->on('workerStop', [$this, 'onWorkerStop']);
        $this->rawServer->on('workerError', [$this, 'onWorkerError']);
        $this->rawServer->on('task', [$this, 'onTask']);
        $this->rawServer->on('finish', [$this, 'onFinish']);
        $this->rawServer->on('open', [$this, 'onOpen']);
        $this->rawServer->on('close', [$this, 'onClose']);
        $this->rawServer->on('message', [$this, 'onMessage']);
        $this->rawServer->on('request', [$this, 'onRequest']);

        $this->rawServer->set($this->options);

        if (is_callable($this->options['on_init'])) {
            call_user_func($this->options['on_init'], $this);
        }

        foreach ($this->handlers as $handler) {
            $handler->onInit($this);
        }

        foreach ($this->workers as $worker) {
            $worker->init();
            $processNumber = $worker->getOption('process_number', 1);
            for ($i = 0; $i < $processNumber; $i++) {
                $this->addWorker($worker);
            }
        }

        $this->fdsInfoTable = new Table(65536);
        $this->fdsInfoTable->column('worker_id', Table::TYPE_INT);
        $this->fdsInfoTable->create();

        $this->rawServer->start();
    }

    protected function addWorker(Worker $worker)
    {
        $this->rawServer->addProcess(new Process(function ($process) use ($worker) {
            $workerName = $worker->getName();
            @cli_set_process_title($this->options['process_title'].': worker/'.$workerName);

            $this->processFlag = '~';
            $this->log('INFO', "worker/$workerName start");

            try {
                pcntl_signal(15, [$this, 'onSignal']);
                $worker->setProcess($process);

                while (true) {
                    pcntl_signal_dispatch();
                    if ($this->exit) {
                        break;
                    }
                    if ($worker->run()) {
                        break;
                    }
                    usleep($worker->getOption('sleep_time', 1000) * 1000);
                }
            } catch (Exception $e) {
                $this->log('ERROR', $e);
            } catch (Throwable $e) {
                $this->log('ERROR', $e);
            }

            $this->log('INFO', "worker/$workerName stop");
            $process->exit(0);
        }));
    }

    public function onSignal($signo)
    {
        $this->log('INFO', "recv signal $signo");

        switch ($signo) {
            case 15:
                $this->exit = true;
                break;
        }
    }

    public function stop()
    {
        $pidFile = $this->getPidFile();
        $pid = $this->getPid();
        if ($pid > 0) {
            posix_kill($pid, 15);
            $timeout = 5;
            for ($i = 0; $i < $timeout * 10; $i++) {
                usleep(100000);
                if (! file_exists($pidFile)) {
                    break;
                }
            }
        }
    }

    public function restart()
    {
        $this->stop();
        $this->start();
    }

    public function reload()
    {
        $pid = $this->getPid();
        if ($pid > 0) {
            posix_kill($pid, 10);
        }
    }

    public function onStart($server)
    {
        @cli_set_process_title($this->options['process_title'].': master');

        $pidFile = $this->getPidFile();
        $pidDir = dirname($pidFile);
        is_dir($pidDir) || mkdir($pidDir, 0755, true);
        file_put_contents($pidFile, $this->rawServer->master_pid);

        if (is_callable($this->options['on_start'])) {
            call_user_func($this->options['on_start'], $this);
        }

        foreach ($this->handlers as $handler) {
            $handler->onStart($this);
        }

        $this->log(
            'INFO',
            sprintf(
                'master start (listening on %s:%d)',
                $this->options['host'],
                $this->options['port']
            )
        );
    }

    public function onShutdown($server)
    {
        $pidFile = $this->getPidFile();
        is_file($pidFile) && unlink($pidFile);

        if (is_callable($this->options['on_shutdown'])) {
            call_user_func($this->options['on_shutdown'], $this);
        }

        foreach ($this->handlers as $handler) {
            $handler->onShutdown($this);
        }

        $this->log('INFO', 'master shutdown');
    }

    public function onManagerStart($server)
    {
        @cli_set_process_title($this->options['process_title'].': manager');

        $this->processFlag = '$';

        $this->log('INFO', 'manager start');
    }

    public function onManagerStop($server)
    {
        $this->log('INFO', 'manager stop');
    }

    public function onWorkerStart($server, $workerId)
    {
        @cli_set_process_title($this->options['process_title'].': worker'.($this->rawServer->taskworker ? '/task' : ''));

        $this->processFlag = $this->rawServer->taskworker ? '^' : '*';

        if (is_callable($this->options['on_worker_start'])) {
            call_user_func($this->options['on_worker_start'], $this);
        }

        foreach ($this->handlers as $handler) {
            $handler->onWorkerStart($this);
        }

        $this->log('INFO', sprintf('worker#%d start', $workerId));
    }

    public function onWorkerStop($server, $workerId)
    {
        if (is_callable($this->options['on_worker_stop'])) {
            call_user_func($this->options['on_worker_stop'], $this);
        }

        foreach ($this->handlers as $handler) {
            $handler->onWorkerStop($this);
        }

        $this->log('INFO', sprintf('worker#%d stop', $workerId));
    }

    public function onWorkerError($server, $workerId, $workerPid, $exitCode)
    {
        $this->log(
            'ERROR',
            sprintf(
                'worker#%d error (pid: %d, status: %d)',
                $workerId,
                $workerPid,
                $exitCode
            )
        );

        foreach ($this->fdsInfoTable as $fd => $info) {
            if ($info['worker_id'] == $workerId) {
                $server->close($fd);
            }
        }
    }

    public function onTask($server, $taskId, $srcWorkerId, $data)
    {
        try {
            if (is_array($data)) {
                if (isset($data['class'])) {
                    $taskClass = (string) $data['class'];
                } else {
                    $taskClass = '';
                }
                if (isset($data['options'])) {
                    $taskOptions = (array) $data['options'];
                } else {
                    $taskOptions = [];
                }
            } else {
                $taskClass = (string) $data;
                $taskOptions = [];
            }

            $task = new $taskClass($this);
            $task->setOptions($taskOptions);
            return [$task->run(), null];
        } catch (Exception $e) {
            $this->log('ERROR', $e);
        } catch (Throwable $e) {
            $this->log('ERROR', $e);
        }

        $error = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        return [null, $error];
    }

    public function onFinish($server, $taskId, $data)
    {
        // pass
    }

    public function onOpen($server, $request)
    {
        $request = new Request($request);

        $fd = $request->fd();
        $this->fdsInfoTable->set($fd, ['worker_id' => $server->worker_id]);

        $handlerPath = $request->path();
        $handler = $this->getHandler($handlerPath);
        if (! $handler) {
            $this->close($fd);
            $this->log('ERROR', sprintf(
                'websocket handler not exist on open callback (fd: %d, path: %s)',
                $fd,
                $handlerPath
            ));
            return;
        }

        $this->fds[$fd] = $handler;

        try {
            $handler->onOpen($this, $request);
        } catch (Exception $e) {
            $this->close($fd);
            $this->log('ERROR', $e);
        } catch (Throwable $e) {
            $this->close($fd);
            $this->log('ERROR', $e);
        }
    }

    public function onClose($server, $fd)
    {
        $this->fdsInfoTable->del($fd);

        $handler = isset($this->fds[$fd]) ? $this->fds[$fd] : null;
        if (! $handler) {
            /*
            $this->log('ERROR', sprintf(
                'websocket handler not exist on close callback (fd: %d)',
                $fd
            ));
            */
            return;
        } else {
            unset($this->fds[$fd]);
        }

        try {
            $handler->onClose($this, $fd);
        } catch (Exception $e) {
            $this->close($fd);
            $this->log('ERROR', $e);
        } catch (Throwable $e) {
            $this->close($fd);
            $this->log('ERROR', $e);
        }
    }

    public function onMessage($server, $frame)
    {
        $fd = $frame->fd;

        $handler = isset($this->fds[$fd]) ? $this->fds[$fd] : null;
        if (! $handler) {
            $this->close($fd);
            $this->log('ERROR', sprintf(
                'websocket handler not exist on message callback (fd: %d, data: %s)',
                $fd,
                $frame->data
            ));
            return;
        }

        try {
            $handler->onMessage($this, $frame);
        } catch (Exception $e) {
            $this->close($fd);
            $this->log('ERROR', $e);
        } catch (Throwable $e) {
            $this->close($fd);
            $this->log('ERROR', $e);
        }
    }

    public function onRequest($request, $response)
    {
        $request = new Request($request);
        $response = new Response($response);

        $handlerPath = $request->path();
        $handler = $this->getHandler($handlerPath);
        if (! $handler) {
            $response->error(404);
            return;
        }

        try {
            $handler->onRequest($this, $request, $response);
        } catch (Exception $e) {
            $response->error(500);
            $this->log('ERROR', $e);
        } catch (Throwable $e) {
            $response->error(500);
            $this->log('ERROR', $e);
        }
    }

    public function getHandler($path)
    {
        foreach ($this->handlers as $handlerPath => $handler) {
            $suffix = substr($handlerPath, -1);
            if ($suffix != '/') {
                if ($handlerPath == $path) {
                    return $handler;
                }
            } else {
                if (substr($path, 0, strlen($handlerPath)) == $handlerPath) {
                    return $handler;
                }
            }
        }
    }

    public function getPidFile()
    {
        return $this->options['pid_file'];
    }

    public function getPid()
    {
        $pidFile = $this->getPidFile();
        if (is_file($pidFile)) {
            if (($pid = intval(file_get_contents($pidFile))) > 0) {
                return $pid;
            }
        }
    }

    public function log($level, $message)
    {
        echo sprintf(
            "[%s %s%d.%d] %s %s\n",
            date('Y-m-d H:i:s', time()),
            $this->processFlag,
            getmypid(),
            isset($this->rawServer->worker_id) ? $this->rawServer->worker_id : 0,
            strtoupper($level),
            $message
        );
    }

    public function rawServer()
    {
        return $this->rawServer;
    }

    public function close($fd, $reset = false)
    {
        $this->rawServer->close($fd, $reset);
    }

    public function push($fd, $data, $opcode = 1, $finish = true)
    {
        $this->rawServer->push($fd, $data, $opcode, $finish);
    }

    public function task($class, array $options = [], callable $callback = null)
    {
        $this->rawServer->task(
            ['class' => $class, 'options' => $options],
            -1,
            function ($server, $taskId, $data) use ($callback) {
                $callback && call_user_func_array($callback, $data);
            }
        );
    }
}
