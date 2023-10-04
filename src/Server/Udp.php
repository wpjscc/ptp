<?php

namespace Wpjscc\Penetration\Server;

use Wpjscc\Penetration\Proxy\ProxyManager;
use RingCentral\Psr7;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\UdpTunnel;

class Udp
{
    protected $ip = '0.0.0.0';
    protected $port;

    public function __construct($ip, $port)
    {

        if ($ip) {
            $this->ip = $ip;
        }
        
        if (!$port) {
            throw new \Exception("port is required");
        }

        $this->port = $port;


    }

    public function run()
    {
     
        $tunnel = new UdpTunnel('0.0.0.0:'.$this->port);

        $tunnel->on('connection', function ($connection) {
            echo 'udp user: '.$connection->getLocalAddress().' is connected'."\n";
            $localAddress = $connection->getLocalAddress();
            $uri = $this->ip.':' .explode(':', $localAddress)[1];

            $request = Psr7\parse_request("GET /client HTTP/1.1\r\nHost: $uri\r\n\r\n");
            ProxyManager::pipe($connection, $request);
            $timer = \React\EventLoop\Loop::get()->addPeriodicTimer(1, function () use ($connection) {
                if ((time() - $connection->activeTime) > 5) {
                    $connection->close();
                }
            });

            $connection->on('close', function () use ($timer) {
                \React\EventLoop\Loop::get()->cancelTimer($timer);
            });

        });

        echo "Udp Server is running at {$this->port}...\n";

        return $tunnel;
    }
}