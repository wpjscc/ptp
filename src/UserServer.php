<?php

namespace Wpjscc\Penetration;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Proxy\ProxyManager;
use RingCentral\Psr7;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;

class UserServer
{
    public $port = 8080;

    public function __construct($port)
    {
        if ($port) {
            $this->port = $port;
        }


    }

    public function run()
    {
     
        $socket = new SocketServer('0.0.0.0:'.$this->port);

        $socket->on('connection', function (ConnectionInterface $userConnection) {
            
            $buffer = '';
            $userConnection->on('data', $fn = function ($chunk) use ($userConnection, &$buffer,  &$fn) {
                $buffer .= $chunk;
                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos !== false) {
                    $userConnection->removeListener('data', $fn);
                    $fn = null;
                    // try to parse headers as request message
                    try {
                        $request = Psr7\parse_request(substr($buffer, 0, $pos+2));
                    } catch (\Exception $e) {
                        // invalid request message, close connection
                        $buffer = '';
                        $userConnection->write($e->getMessage());
                        $userConnection->close();
                        return;
                    }

                    $host = $request->getUri()->getHost();
                    $port = $request->getUri()->getPort();
                    $uri = $host;
                    if ($port) {
                        $uri = $uri.':'.$port;
                    }

                    $proxyConnection = ProxyManager::getProxyConnection($uri, true);
                    if ($proxyConnection === false) {
                        $buffer = '';
                        $userConnection->write('no proxy connection');
                        $userConnection->close();
                    } else {
                        $proxyConnection->pipe($uri, $userConnection, $buffer);
                    }

                }
                

            });
            

        });

        echo "Server is running at {$this->port}...\n";
    }

    public function getProxyConnection()
    {

    }
}