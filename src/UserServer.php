<?php

namespace Wpjscc\Penetration;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Proxy\ProxyManager;
use RingCentral\Psr7;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use Wpjscc\Penetration\Helper;

class UserServer
{
    public $port = 8080;

    public function __construct($port = null)
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
                        $content = "no proxy connection\n";
                        $headers = [
                            'HTTP/1.1 200 OK',
                            'Server: ReactPHP/1',
                            'Content-Type: text/html; charset=UTF-8',
                            'Content-Length: '.strlen($content),
                        ];
                        $userConnection->write(implode("\r\n", $headers)."\r\n\r\n".$content);
                        $userConnection->end();
                    } else {
                        echo 'user: '.$uri.' is arive'."\n";
                        $proxyConnection->pipe($userConnection, $buffer);
                    }

                }
                

            });
            

        });

        echo "User Server is running at {$this->port}...\n";
    }

    public function getProxyConnection()
    {

    }
}