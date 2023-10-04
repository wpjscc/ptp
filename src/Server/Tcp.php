<?php

namespace Wpjscc\Penetration\Server;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Proxy\ProxyManager;
use RingCentral\Psr7;

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
     
        $socket = new SocketServer('0.0.0.0:'.$this->port);

        $socket->on('connection', function (ConnectionInterface $userConnection) {
            echo 'user: '.$userConnection->getLocalAddress().' is connected'."\n";
            $localAddress = $userConnection->getLocalAddress();
            $uri = $this->ip.':' .explode(':', $localAddress)[2];

            $request = Psr7\parse_request("GET /client HTTP/1.1\r\nHost: $uri}\r\n\r\n");
            ProxyManager::pipe($userConnection, $request, '');

            // $proxyConnection = ProxyManager::getProxyConnection($uri);
            // if ($proxyConnection === false) {
            //     echo "tcp no proxy connection";
            //     $content = "no proxy connection\n";
            //     $headers = [
            //         'HTTP/1.1 200 OK',
            //         'Server: ReactPHP/1',
            //         'Content-Type: text/html; charset=UTF-8',
            //         'Content-Length: '.strlen($content),
            //     ];
            //     $userConnection->write(implode("\r\n", $headers)."\r\n\r\n".$content);
            //     $userConnection->end();
            // } else {
            //     echo 'user: '.$uri.' is arive'."\n";
            //     $buffer = '';
            //     $proxyConnection->pipe($userConnection, $buffer, null);
            // }
        });

        echo "Tcp Server is running at {$this->port}...\n";

        return $socket;
    }
}