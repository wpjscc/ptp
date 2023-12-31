<?php

namespace Wpjscc\PTP\Server;

use Wpjscc\PTP\Config;

class TcpManager
{
    use \Wpjscc\PTP\Traits\Singleton;
    use \Wpjscc\PTP\Traits\RunPort;


    protected function init()
    {
        $this->ip = Config::instance($this->key)->getTcpIp();
        $this->ports = Config::instance($this->key)->getTcpPorts();
    }

    protected function runPort($port)
    {
        $this->sockets[$port] = (new Tcp(
            $this->ip,
            $port
        ))->run();
    }

    public function check()
    {
        $this->checkPorts(Config::instance($this->key)->getTcpPorts());
    }

}