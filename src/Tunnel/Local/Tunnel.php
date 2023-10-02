<?php

namespace Wpjscc\Penetration\Tunnel\Local;


use Wpjscc\Penetration\Tunnel\Local\Tunnel\TcpTunnel;
use Wpjscc\Penetration\Tunnel\Local\Tunnel\UdpTunnel;

class Tunnel implements \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function getTunnel($protocol = null)
    {
        if (!$protocol) {
            $protocol = 'tcp';
        }

        if (in_array($protocol, ['tcp', 'http', 'https', 'tls', 'ws', 'wss'])) {
           return (new TcpTunnel($this->config))->connect($protocol);
        }
        elseif ($protocol == 'udp') {
            return (new UdpTunnel($this->config))->connect($protocol);
        } 
        else {
            throw new \Exception('Unsupported protocol: ' . $protocol);
        }

    }
}