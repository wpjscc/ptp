<?php

namespace Wpjscc\Penetration\Server;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Proxy\ProxyManager;
use RingCentral\Psr7;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\TcpTunnel;

class Http implements \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    public $port = 8080;

    public function __construct($port = null)
    {
        if ($port) {
            $this->port = $port;
        }


    }

    public function run()
    {
     
        $tunnel = new TcpTunnel('0.0.0.0:'.$this->port);

        $tunnel->on('connection', function ($userConnection) {
            echo 'http user: '.$userConnection->getLocalAddress().' is connected'."\n";
            
            $buffer = '';
            $userConnection->on('data', $fn = function ($chunk) use ($userConnection, &$buffer,  &$fn) {
                $buffer .= $chunk;
                $pos = strpos($buffer, "\r\n\r\n");

                // CONNECT
                if (($pos !== false) && (strpos($buffer, "CONNECT") === 0)) {
                    $userConnection->removeListener('data', $fn);
                    $fn = null;
                    try {
                        $pattern = "/CONNECT ([^\s]+) HTTP\/(\d+\.\d+)/";
                        if (preg_match($pattern, $buffer, $matches)) {
                            $host = $matches[1];
                            $version = $matches[2];
                            $userConnection->write("HTTP/{$version} 200 Connection Established\r\n\r\n");
                            $request = Psr7\parse_request("GET /connect HTTP/1.1\r\nHost: $host}\r\n\r\n");
                            ProxyManager::pipe($userConnection, $request);
                            $buffer = '';
                        } else {
                            $buffer = '';
                            $userConnection->write('Invalid request');
                            $userConnection->end();
                        }
                    } catch (\Exception $e) {
                        static::getLogger()->error($e->getMessage(), [
                            'class' => __CLASS__,
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ]);
                        $buffer = '';
                        $userConnection->write($e->getMessage());
                        $userConnection->end();
                    }
                    return;
                }

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

                }
                

            });
            

        });

        echo "Http and Proxy Server is running at {$this->port}...\n";
    }
}