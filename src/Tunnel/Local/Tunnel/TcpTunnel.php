<?php

namespace Wpjscc\PTP\Tunnel\Local\Tunnel;

use React\Socket\Connector;

class TcpTunnel implements \React\Socket\ConnectorInterface
{
    protected $config;
    protected $proxyHeader;
    public function __construct($config, $proxyHeader = [])
    {
        $this->config = $config;
        $this->proxyHeader = $proxyHeader;
    }

    public function connect($protocol = null)
    {

        if (in_array($protocol, ['https', 'tls', 'wss'])) {
            $protocol = 'tls';
        } else {
            $protocol = 'tcp';
        }

        $config  = $this->config;
        $proxy = null;

        if ($config['local_http_proxy'] ?? '') {
            $proxy = new \Clue\React\HttpProxy\ProxyConnector(
                $config['local_http_proxy'],
                new Connector([
                    'tls' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ]
                ]),
                $this->proxyHeader
            );
        }

        if (!$proxy && ($config['local_socks_proxy'] ?? '')) {
            $proxy = new \Clue\React\Socks\Client(
                $config['local_socks_proxy'],
            );
        }

        return (new Connector(
            array_merge(
                array(
                    'timeout' => $config['timeout'],
                ),
                ($proxy ? [
                    'tcp' => $proxy,
                    'dns' => false,
                ] : [])
            )
        ))->connect($protocol . "://" . $config['local_host'] . ":" . $config['local_port']);
    }
}
