<?php

namespace Wpjscc\Penetration\Tunnel\Server;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Proxy\ProxyManager;
use Wpjscc\Penetration\Client\ClientConnection;
use RingCentral\Psr7;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\TcpTunnel;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\UdpTunnel;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\WebsocketTunnel;


class Tunnel
{
    public $protocol = 'tcp';
    public $host = 'localhost';
    public $port;
    public $certPemPath = '';
    public $certKeyPath = '';

    public function __construct($config)
    {

        $this->protocol = $config['tunnel_protocol'] ?? 'tcp';
        $this->port = $config['server_port'];
        $this->certPemPath = $config['cert_pem_path'] ?? '';
        $this->certKeyPath = $config['cert_key_path'] ?? '';

    }

    public function getTunnel()
    {
        $context = [];

        if ($this->certPemPath) {
            $context = [
                'tls' => array(
                    'local_cert' => $this->certPemPath,
                    'local_pk' => $this->certKeyPath,
                )
            ];
        }

        if ($this->protocol == 'websocket') {

           $socket = new WebsocketTunnel($this->host, $this->port, '0.0.0.0', null, $context);
        } 
        else if ($this->protocol == 'udp') {
            $socket = new UdpTunnel('0.0.0.0:'.$this->port);
        }
        else {
            if ($this->certPemPath) {
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
            echo 'client: '.$connection->getRemoteAddress().' is connected'."\n";
            
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
                        echo 'client: '.$connection->getRemoteAddress().' is unauthorized'."\n";
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

                    ProxyManager::handleClientConnection($connection, $request);
                }

                

            });

        });

        echo "Client Server is protocol is  {$this->protocol}...\n";
        echo "Client Server is running at {$this->port}...\n";
    }

    public function validate($request)
    {
        $domain = $request->getHeaderLine('Domain');

        if (isset(ProxyManager::$uriToToken[$domain])) {
            if (ProxyManager::$uriToToken[$domain]!=$request->getHeaderLine('Authorization')) {
                return false;
            }
        } else {
            ProxyManager::$uriToToken[$domain] = $request->getHeaderLine('Authorization');
        }


        return [
            'uri' => $request->getHeaderLine('Domain'),
        ];
    }
}