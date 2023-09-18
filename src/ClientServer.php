<?php

namespace Wpjscc\Penetration;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Client\ClientConnection;
use RingCentral\Psr7;


class ClientServer
{
    public $port = 32123;
    public $certPath = '';

    public function __construct($port = null, $certPath = '')
    {
        if ($port) {
            $this->port = $port;
        }
        if ($certPath) {
            $this->certPath = $certPath;
        }

    }

    public function run()
    {
        
        if ($this->certPath) {
            $socket = new SocketServer('tls://0.0.0.0:'.$this->port, [
                'tls' => array(
                    'local_cert' => $this->certPath
                )
            ]);
        } else {
            $socket = new SocketServer('0.0.0.0:'.$this->port);
        }


        $socket->on('connection', function (ConnectionInterface $connection) {
            echo 'user: '.$connection->getRemoteAddress().' is connected'."\n";
            
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
                    $state = false;
                    try {
                        $state =  $that->validate($request);
                    } catch (\Throwable $th) {
                        echo $th->getMessage();
                    }

                    if (!$state) {
                        $headers = [
                            'HTTP/1.1 401 Unauthorized',
                            'Server: ReactPHP/1',
                        ];
                        $connection->write(implode("\r\n", $headers)."\r\n\r\n");
                        $connection->end();
                        return ;
                    }

                    $headers = [
                        'HTTP/1.1 200 OK',
                        'Server: ReactPHP/1',
                        'Uri: '.$state['uri'],
                    ];
                    $connection->write(implode("\r\n", $headers)."\r\n\r\n");
                    $request = $request->withoutHeader('Uri');
                    $request = $request->withHeader('Uri', $state['uri']);

                    ClientManager::handleClientConnection($connection, $request);
                }

                

            });

        });

        echo "Client Server is running at {$this->port}...\n";
    }

    public function validate($request)
    {
        $remoteDomain = $request->getHeaderLine('Remote-Domain');

        if (isset(ClientManager::$uriToToken[$remoteDomain])) {
            if (ClientManager::$uriToToken[$remoteDomain]!=$request->getHeaderLine('Authorization')) {
                return false;
            }
        } else {
            ClientManager::$uriToToken[$remoteDomain] = $request->getHeaderLine('Authorization');
        }


        return [
            'uri' => $request->getHeaderLine('Remote-Domain'),
        ];
    }
}