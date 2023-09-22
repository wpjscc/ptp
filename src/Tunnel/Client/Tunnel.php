<?php

namespace Wpjscc\Penetration\Tunnel\Client;


use Wpjscc\Penetration\Tunnel\Client\Tunnel\TcpTunnel;
use Wpjscc\Penetration\Tunnel\Client\Tunnel\UdpTunnel;
use Wpjscc\Penetration\Tunnel\Client\Tunnel\WebsocketTunnel;


class Tunnel
{
    public $protocol = 'tcp';
    public $serverHost;
    public $server80port;
    public $server443port;

    public $timeout;

    public function __construct($config)
    {

        $this->protocol = $config['tunnel_protocol'] ?? 'tcp';
        $this->serverHost = $config['server_host'];
        $this->server80port = $config['server_80_port'];
        $this->server443port = $config['server_443_port'] ?? '';
        $this->timeout = $config['timeout'] ?? 6;

    }

    public function getTunnel($protocol = null)
    {

        if (!$protocol) {
            $protocol = $this->protocol;
        }

        echo "protocol: ".$protocol."\n";

        if ($protocol == 'ws') {
            $tunnel = (new WebsocketTunnel())->connect("ws://".$this->serverHost.":".$this->server80port);
        }
        elseif ($protocol == 'wss') {
            if (!$this->server443port) {
                throw new \Exception('wss protocol must set server_443_port');
            }
            $tunnel = (new WebsocketTunnel())->connect("wss://".$this->serverHost.":".$this->server443port);
        }
        else if ($protocol == 'udp') {
            var_dump($protocol);
            $tunnel = (new UdpTunnel())->connect($this->serverHost.":".$this->server80port);
        }
        elseif ($protocol == 'tls') {
            if (!$this->server443port) {
                throw new \Exception('tls protocol must set server_443_port');
            }
            $tunnel = (new TcpTunnel(array('timeout' => $this->timeout)))->connect("tls://".$this->serverHost.":".$this->server443port);
        }
        else {
            $tunnel = (new TcpTunnel(array('timeout' => $this->timeout)))->connect("tcp://".$this->serverHost.":".$this->server80port);
        }
        return $tunnel;
    }
}