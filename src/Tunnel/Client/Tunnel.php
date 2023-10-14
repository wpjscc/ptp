<?php

namespace Wpjscc\PTP\Tunnel\Client;

use Wpjscc\PTP\Tunnel\Client\Tunnel\P2pTunnel;
use Wpjscc\PTP\Tunnel\Client\Tunnel\TcpTunnel;
use Wpjscc\PTP\Tunnel\Client\Tunnel\UdpTunnel;
use Wpjscc\PTP\Tunnel\Client\Tunnel\WebsocketTunnel;


class Tunnel implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    private $config;
    public $protocol = 'tcp';
    public $tunnelHost;
    public $tunnel80Port;
    public $tunnel443Port;

    public $timeout;

    public function __construct(&$config)
    {

        $this->config = &$config;
        $this->protocol = $config['tunnel_protocol'] ?? 'tcp';
        $this->tunnelHost = $config['tunnel_host'];
        $this->tunnel80Port = $config['tunnel_80_port'];
        $this->tunnel443Port = $config['tunnel_443_port'] ?? '';
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
            $tunnel = (new WebsocketTunnel())->connect("ws://".$this->tunnelHost.":".$this->tunnel80Port);
        }
        elseif ($protocol == 'wss') {
            if (!$this->tunnel443Port) {
                static::getLogger()->error('wss protocol must set tunnel_443_port', [
                    'tunnel443Port' => $this->tunnel443Port,
                ]);
                throw new \Exception('wss protocol must set tunnel_443_port');
            }
            $tunnel = (new WebsocketTunnel())->connect("wss://".$this->tunnelHost.":".$this->tunnel443Port);
        }
        else if ($protocol == 'udp') {
            $tunnel = (new UdpTunnel(false))->connect($this->tunnelHost.":".$this->tunnel80Port);
        }
        elseif ($protocol == 'tls') {
            if (!$this->tunnel443Port) {
                static::getLogger()->error('tls protocol must set tunnel_443_port', [
                    'tunnel443Port' => $this->tunnel443Port,
                ]);
                throw new \Exception('tls protocol must set tunnel_443_port');
            }
            $tunnel = (new TcpTunnel(array('timeout' => $this->timeout)))->connect("tls://".$this->tunnelHost.":".$this->tunnel443Port);
        }
        elseif ($protocol == 'p2p') {
            $tunnel = (new P2pTunnel($this->config))->connect("udp://" . $this->tunnelHost . ":" . $this->tunnel80Port);
        }
        else {
            $tunnel = (new TcpTunnel(array('timeout' => $this->timeout)))->connect("tcp://".$this->tunnelHost.":".$this->tunnel80Port);
        }
        return $tunnel;
    }
}