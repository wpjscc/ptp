<?php

namespace Wpjscc\PTP\Server;

use Wpjscc\PTP\Config;

class HttpManager
{
    use \Wpjscc\PTP\Traits\Singleton;
    use \Wpjscc\PTP\Traits\RunPort;

    protected function init()
    {
        $this->ip = '0.0.0.0';
        $this->ports = Config::instance($this->key)->getHttpPorts();
    }

    protected function runPort($port)
    {
        $this->sockets[$port] = (new Http(
            $port
        ))->run();
    }

    public function check()
    {
        $this->checkPorts(Config::instance($this->key)->getHttpPorts());
    }

}