<?php

namespace Wpjscc\Penetration\Tunnel\Client;


use Wpjscc\Penetration\Tunnel\Client\Tunnel\TcpTunnel;
use Wpjscc\Penetration\Tunnel\Client\Tunnel\UdpTunnel;
use Wpjscc\Penetration\Tunnel\Client\Tunnel\WebsocketTunnel;


class Tunnel
{
    public $protocol = 'tcp';
    public $serverHost;
    public $serverPort;
    public $serverTls;

    public $timeout;

    public function __construct($config)
    {

        $this->protocol = $config['tunnel_protocol'] ?? 'tcp';
        $this->serverHost = $config['server_host'];
        $this->serverPort = $config['server_port'];
        $this->serverTls = $config['server_tls'] ?? false;
        $this->timeout = $config['timeout'] ?? 6;

    }

    public function getTunnel($protocol = null)
    {

        if (!$protocol) {
            $protocol = $this->protocol;
        }

        if ($protocol == 'ws') {
            $tunnel = (new WebsocketTunnel())->connect("ws://".$this->serverHost.":".$this->serverPort);
        }
        elseif ($protocol == 'wss') {
            $tunnel = (new WebsocketTunnel())->connect("wss://".$this->serverHost.":".$this->serverPort);
        }
        else if ($protocol == 'udp') {
            var_dump($protocol);
            $tunnel = (new UdpTunnel())->connect($this->serverHost.":".$this->serverPort);
        }
        elseif ($protocol == 'tls') {
            $tunnel = (new TcpTunnel(array('timeout' => $this->timeout)))->connect("tls://".$this->serverHost.":".$this->serverPort);
        }
        else {
            $tunnel = (new TcpTunnel(array('timeout' => $this->timeout)))->connect("tcp://".$this->serverHost.":".$this->serverPort);
        }
        return $tunnel;
    }
}