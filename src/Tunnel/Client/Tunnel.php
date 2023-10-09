<?php

namespace Wpjscc\Penetration\Tunnel\Client;

use Wpjscc\Penetration\Tunnel\Client\Tunnel\P2pTunnel;
use Wpjscc\Penetration\Tunnel\Client\Tunnel\TcpTunnel;
use Wpjscc\Penetration\Tunnel\Client\Tunnel\UdpTunnel;
use Wpjscc\Penetration\Tunnel\Client\Tunnel\WebsocketTunnel;


class Tunnel implements \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    private $config;
    public $protocol = 'tcp';
    public $serverHost;
    public $server80port;
    public $server443port;

    public $timeout;

    public function __construct(&$config)
    {

        $this->config = &$config;
        $this->protocol = $config['tunnel_protocol'] ?? 'tcp';
        $this->serverHost = $config['tunnel_host'];
        $this->server80port = $config['tunnel_80_port'];
        $this->server443port = $config['tunnel_443_port'] ?? '';
        $this->timeout = $config['timeout'] ?? 6;

    }

    public function getTunnel($protocol = null)
    {

        if (!$protocol) {
            $protocol = $this->protocol;
        }

        static::getLogger()->info(__FUNCTION__, [
            'class' => __CLASS__,
            'protocol' => $protocol,
        ]);

        if ($protocol == 'ws') {
            $tunnel = (new WebsocketTunnel())->connect("ws://".$this->serverHost.":".$this->server80port);
        }
        elseif ($protocol == 'wss') {
            if (!$this->server443port) {
                static::getLogger()->error('wss protocol must set tunnel_443_port', [
                    'server443port' => $this->server443port,
                ]);
                throw new \Exception('wss protocol must set tunnel_443_port');
            }
            $tunnel = (new WebsocketTunnel())->connect("wss://".$this->serverHost.":".$this->server443port);
        }
        else if ($protocol == 'udp') {
            $tunnel = (new UdpTunnel(false))->connect($this->serverHost.":".$this->server80port);
        }
        elseif ($protocol == 'tls') {
            if (!$this->server443port) {
                static::getLogger()->error('tls protocol must set tunnel_443_port', [
                    'server443port' => $this->server443port,
                ]);
                throw new \Exception('tls protocol must set tunnel_443_port');
            }
            $tunnel = (new TcpTunnel(array('timeout' => $this->timeout)))->connect("tls://".$this->serverHost.":".$this->server443port);
        }
        elseif ($protocol == 'p2p') {
            $tunnel = (new P2pTunnel($this->config))->connect("udp://" . $this->serverHost . ":" . $this->server80port);
        }
        else {
            $tunnel = (new TcpTunnel(array('timeout' => $this->timeout)))->connect("tcp://".$this->serverHost.":".$this->server80port);
        }
        return $tunnel;
    }
}