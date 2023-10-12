<?php

namespace Wpjscc\Penetration\Server;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Proxy\ProxyManager;
use RingCentral\Psr7;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\TcpTunnel;

class Tcp
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
     
        $tunnel = new TcpTunnel('0.0.0.0:'.$this->port);

        $tunnel->on('connection', function (ConnectionInterface $userConnection) {
            echo 'tcp user: '.$userConnection->getLocalAddress().' is connected'."\n";
            $localAddress = $userConnection->getLocalAddress();
            $uri = $this->ip.':' .explode(':', $localAddress)[2];
            $request = Psr7\parse_request("GET /client HTTP/1.1\r\nHost: $uri\r\n\r\n");
            ProxyManager::pipe($userConnection, $request);

        });

        echo "Tcp Server is running at {$this->port}...\n";

        return $tunnel;
    }
}