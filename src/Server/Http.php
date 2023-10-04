<?php

namespace Wpjscc\Penetration\Server;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Proxy\ProxyManager;
use RingCentral\Psr7;

class Http
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
            echo 'user: '.$userConnection->getLocalAddress().' is connected'."\n";
            
            $buffer = '';
            $userConnection->on('data', $fn = function ($chunk) use ($userConnection, &$buffer,  &$fn) {
                $buffer .= $chunk;
                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos !== false) {
                    $userConnection->removeListener('data', $fn);
                    $fn = null;
                    // try to parse headers as request message
                    try {
                        $request = Psr7\parse_request(substr($buffer, 0, $pos));
                    } catch (\Exception $e) {
                        // invalid request message, close connection
                        $buffer = '';
                        $userConnection->write($e->getMessage());
                        $userConnection->close();
                        return;
                    }

                    ProxyManager::pipe($userConnection, $request, $buffer);

                    // $host = $request->getUri()->getHost();
                    // $port = $request->getUri()->getPort();
                    // $uri = $host;

                    // try {
                        
                    //     IPv4::factory($host);
                    //     if ($port) {
                    //         $uri = $uri.':'.$port;
                    //     }

                    // } catch (Exception\InvalidIpAddressException $e) {
                    //     echo 'The IP address supplied is invalid!';
                    // }

                    // var_dump('userConnection', $uri);
                   
                    

                    // $proxyConnection = ProxyManager::getProxyConnection($uri);
                    // if ($proxyConnection === false) {
                    //     $buffer = '';
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
                    //     $proxyConnection->pipe($userConnection, $buffer, $request);
                    // }

                }
                

            });
            

        });

        echo "Http Server is running at {$this->port}...\n";
    }
}