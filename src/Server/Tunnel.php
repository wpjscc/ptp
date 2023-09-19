<?php

namespace Wpjscc\Penetration\Server;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Client\ClientConnection;
use RingCentral\Psr7;
use Wpjscc\Penetration\Server\Tunnel\TcpTunnel;
use Wpjscc\Penetration\Server\Tunnel\WebsocketTunnel;


class Tunnel
{
    public $protocol = 'tcp';
    public $host = '0.0.0.0';
    public $port = 32123;
    public $certPem = '';
    public $certKey = '';

    public function __construct($protocol = 'tcp', $host = '0.0.0.0', $port = null, $certPem = '', $certKey = '')
    {
        $this->protocol = $protocol;

        if ($host) {
            $this->host = $host;
        }

        if ($port) {
            $this->port = $port;
        }

        $this->certPem = $certPem;
        $this->certKey = $certKey;

        var_dump($this->protocol, $this->host, $this->port, $this->certPem, $this->certKey);

    }

    public function getTunnel()
    {
        $context = [];

        if ($this->certPem) {
            $context = [
                'tls' => array(
                    'local_cert' => $this->certPem,
                    'local_pk' => $this->certKey,
                )
            ];
        }

        if ($this->protocol == 'websocket') {

           $socket = new WebsocketTunnel($this->host, $this->port, '0.0.0.0', null, $context);
        } else {
            if ($this->certPem) {
                $socket = new TcpTunnel('tls://0.0.0.0:'.$this->port, $context);
            } else {
                $socket = new TcpTunnel('0.0.0.0:'.$this->port, $context);
            }
        }
        return $socket;
    }

    public function run()
    {
        
        $socket = $this->getTunnel();


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

        echo "Client Server is protocol is  {$this->protocol}...\n";
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