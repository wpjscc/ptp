<?php

namespace Wpjscc\PTP\Server;

use Wpjscc\PTP\Config;

class UdpManager
{
    use \Wpjscc\PTP\Traits\Singleton;
    use \Wpjscc\PTP\Traits\RunPort;

    protected function init()
    {
        $this->ip = Config::instance($this->key)->getUdpIp();
        $this->ports = Config::instance($this->key)->getUdpPorts();
    }

    protected function runPort($port)
    {
        $this->sockets[$port] = (new Udp(
            $this->ip,
            $port
        ))->run();
    }

    public function check()
    {
        $this->checkPorts(Config::instance($this->key)->getUdpPorts());
    }

}