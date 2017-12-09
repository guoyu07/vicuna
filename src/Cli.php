<?php

namespace Vicuna;

use Composer\Script\Event;

class Cli
{
    public static function run(Event $event = null)
    {
        if (php_sapi_name() != 'cli') {
            return;
        }

        spl_autoload_register(function ($class) {
            $namespace = __NAMESPACE__.'\\';
            if (substr($class, 0, strlen($namespace)) == $namespace) {
                $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
                if (file_exists($file)) {
                    require $file;
                }
            }
        });

        if ($event) {
            $bin = 'composer run-script '.$event->getName();
            $args = $event->getArguments();
        } else {
            $bin = $_SERVER['argv'][0];
            $args = array_slice($_SERVER['argv'], 1);
        }

        $action = isset($args[0]) ? $args[0] : '';
        $configFile = isset($args[1]) ? $args[2] : (string) getenv('VICUNA_SERVER_CONFIG_FILE');
        if (file_exists($configFile)) {
            $config = (array) require $configFile;
        } else {
            $config = [];
        }

        switch ($action) {
            case 'start':
            case 'stop':
            case 'restart':
            case 'reload':
                call_user_func([new Server($config), $action]);
                break;
            default:
                printf(
                    "Usage: %s {start|stop|restart|reload} <config-file>\n",
                    $bin
                );
        }
    }
}
