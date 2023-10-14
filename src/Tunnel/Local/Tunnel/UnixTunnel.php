<?php

namespace Wpjscc\PTP\Tunnel\Local\Tunnel;

use React\Socket\Connector;
use React\Socket\UnixConnector;

class UnixTunnel
{
    protected $config;
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function connect($protocol = null)
    {
        $config  = $this->config;
        
        $localHost = $config['local_host'] ?? '';

        if (strpos('unix://', $localHost) !== 0) {
            $localHost = 'unix://' . $localHost;
        }

        return (new UnixConnector())->connect($config['local_host']);
    }
}
