<?php

namespace Wpjscc\PTP\Server;

use Wpjscc\PTP\Config;

class TcpProxyManager
{
    use \Wpjscc\PTP\Traits\Singleton;
    use \Wpjscc\PTP\Traits\RunPort;


    protected $configs = [];

    protected function init()
    {
        $tcpProxy = Config::instance($this->key)->getValue('tcp-proxy');
        foreach ($tcpProxy as $key => $proxy) {
            if ($key == 'ip') {
                $this->ip = $proxy;
                continue;
            } else {
                $this->configs[$proxy['port']] = $proxy;
                $this->ports[] = $proxy['port'];
            }
        }
    }

    public function run()
    {
        if ($this->running) {
            return;
        }

        foreach ($this->ports as $port) {
            $ip = $this->configs[$port]['ip'] ?? $this->ip;
            $this->_runPort($ip, $port, $this->configs[$port]['proxy_host'], $this->configs[$port]['proxy_port']);
        }
        $this->running = true;
    }

    protected function runPort($port)
    {
        
    }

    protected function _runPort($ip, $port, $proxyHost, $proxyPort)
    {
        $this->sockets[$port] = (new TcpProxy(
            $ip,
            $port,
            $proxyHost,
            $proxyPort
        ))->run();
    }


    public function check()
    {
    }
}
