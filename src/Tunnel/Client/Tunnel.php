<?php

namespace Wpjscc\Penetration\Tunnel\Client;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Proxy\ProxyManager;
use Wpjscc\Penetration\Client\ClientConnection;
use RingCentral\Psr7;
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

        if ($protocol == 'websocket') {
            $tunnel = (new WebsocketTunnel())->connect(($this->serverTls ? 'wss' : 'ws')."://".$this->serverHost.":".$this->serverPort);
        }

        else if ($protocol == 'udp') {
            $tunnel = (new UdpTunnel())->connect($this->serverHost.":".$this->serverPort);
        }
        
        else {
            $tunnel = (new TcpTunnel(array('timeout' => $this->timeout)))->connect(($this->serverTls ? 'tls' : 'tcp')."://".$this->serverHost.":".$this->serverPort);
        }
        return $tunnel;
    }
}