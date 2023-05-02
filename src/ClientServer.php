<?php

namespace Wpjscc\Penetration;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Client\ClientConnection;
use RingCentral\Psr7;


class ClientServer
{
    public $port = 8081;

    public $proxyConnection;
    public $proxyManager;

    public function __construct($port)
    {
        if ($port) {
            $this->port = $port;
        }

    }

    public function run()
    {
     
        $socket = new SocketServer('0.0.0.0:'.$this->port);

        $socket->on('connection', function (ConnectionInterface $connection) {
            
            $buffer = '';
            $that = $this;
            $connection->on('data', $fn = function ($chunk) use ($connection, &$buffer, &$fn, $that) {
                $buffer .= $chunk;
                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos !== false) {
                    $connection->removeListener('data', $fn);
                    $fn = null;
                    // try to parse headers as request message
                    try {
                        $request = Psr7\parse_request(substr($buffer, 0, $pos));
                    } catch (\Exception $e) {
                        // invalid request message, close connection
                        $buffer = '';
                        $connection->write($e->getMessage());
                        $connection->close();
                        return;
                    }

                    $buffer = '';

                    $state =  $that->validate($request);

                    if (!$state) {
                        $connection->write('Auth failed');
                        $connection->close();
                        return ;
                    }
                    $headers = [
                        'HTTP/1.1 200 OK',
                        'Server: ReactPHP/1',
                        'Uri: 127.0.0.1:8080'
                    ];
                    $connection->write(implode("\r\n", $headers)."\r\n\r\n");

                    ClientManager::addClientConnection($connection, $request);


                }
                

            });
            

        });

        echo "Server is running at {$this->port}...\n";
    }

    public function validate($request)
    {
        return [
            'uri' => '127.0.0.1:8080'
        ];
    }
}