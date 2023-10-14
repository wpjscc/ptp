<?php

namespace Wpjscc\PTP\Tunnel\Local;


use Wpjscc\PTP\Tunnel\Local\Tunnel\TcpTunnel;
use Wpjscc\PTP\Tunnel\Local\Tunnel\UdpTunnel;
use Wpjscc\PTP\Tunnel\Local\Tunnel\UnixTunnel;

class Tunnel implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

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
        elseif ($protocol == 'unix') {
            return (new UnixTunnel($this->config))->connect($protocol);
        } 
        else {
            throw new \Exception('Unsupported protocol: ' . $protocol);
        }

    }
}