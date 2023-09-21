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
use Wpjscc\Penetration\DecorateSocket;


class Tunnel
{
    public $protocol = 'tcp';
    public $host = 'localhost';
    public $port;
    public $certPemPath = '';
    public $certKeyPath = '';

    public function __construct($config)
    {

        $host = $config['server_host'] ?? '';
        if ($host) {
            $this->host = $host;
        }
        $this->protocol = $config['tunnel_protocol'] ?? 'tcp';
        $this->port = $config['server_port'];
        $this->certPemPath = $config['cert_pem_path'] ?? '';
        $this->certKeyPath = $config['cert_key_path'] ?? '';
    }

    public function getTunnel($protocol = null, $socket = null)
    {

        if (!$protocol) {
            $protocol = $this->protocol;
        }

        $context = [];

        if ($this->certPemPath) {
            $context = [
                'tls' => array(
                    'local_cert' => $this->certPemPath,
                    'local_pk' => $this->certKeyPath,
                )
            ];
        }

        if ($protocol == 'ws') {
            $socket = new WebsocketTunnel($this->host, $this->port, '0.0.0.0', null, $context, $socket);
        } 
        else if ($protocol == 'wss') {
            if (!$this->certPemPath) {
                throw new \Exception('wss protocol must set cert_pem_path and cert_key_path');
            }
            $socket = new WebsocketTunnel($this->host, $this->port, '0.0.0.0', null, $context, $socket);
        }
        
        else if ($protocol == 'udp') {
            $socket = new UdpTunnel('0.0.0.0:' . $this->port);
        }

        else if ($protocol == 'tls') {
            if (!$this->certPemPath) {
                throw new \Exception('tls protocol must set cert_pem_path and cert_key_path');
            }
            $socket = new TcpTunnel('tls://0.0.0.0:' . $this->port, $context);
        } 
        else {
            $socket = new TcpTunnel('0.0.0.0:' . $this->port, $context);
        }
        return $socket;
    }

    public function run()
    {
        $protocols = explode(',', $this->protocol);

        foreach ($protocols as $protocol) {

            if ($protocol == 'ws' || $protocol == 'wss') {
                continue;
            }

            $this->listenTunnel($protocol, $this->getTunnel($protocol));

            echo "Client Server is running at {$protocol}:{$this->port}...\n";
        }
    }

    protected function listenTunnel($protocol, $socket)
    {
        $socket->on('connection', function (ConnectionInterface $connection) use ($protocol, $socket) {
            echo 'client: ' . $connection->getRemoteAddress() . ' is connected' . "\n";

            $buffer = '';
            $that = $this;
            $connection->on('data', $fn = function ($chunk) use ($connection, &$buffer, &$fn, $that, $protocol, $socket) {

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

                    // websocket协议
                    if ($protocol == 'tcp') {
                        $upgradeHeader = $request->getHeader('Upgrade');
                        if ((1 === count($upgradeHeader) && 'websocket' === strtolower($upgradeHeader[0]))) {
                            echo "tcp upgrade to websocket\n";
                            $decoratedSocket = new DecorateSocket($socket);
                            $scheme = $request->getUri()->getScheme() == 'https' ? 'wss' :'ws';
                            $websocketTunnel = $that->getTunnel($scheme, $decoratedSocket);
                            $this->listenTunnel('websocket', $websocketTunnel);
                            $decoratedSocket->emit('connection', [$connection]);
                            $connection->emit('data', [$buffer]);
                            $buffer = '';
                            return;
                        }
                    }

                    $buffer = '';
                    $state = false;
                    try {
                        $state =  $that->validate($request);
                    } catch (\Throwable $th) {
                        echo $th->getMessage();
                    }

                    if (!$state) {
                        echo 'client: ' . $connection->getRemoteAddress() . ' is unauthorized' . "\n";
                        $headers = [
                            'HTTP/1.1 401 Unauthorized',
                            'Server: ReactPHP/1',
                        ];
                        $connection->write(implode("\r\n", $headers) . "\r\n\r\n");
                        $connection->end();
                        return;
                    }

                    $headers = [
                        'HTTP/1.1 200 OK',
                        'Server: ReactPHP/1',
                        'Uri: ' . $state['uri'],
                    ];
                    $connection->write(implode("\r\n", $headers) . "\r\n\r\n");
                    $request = $request->withoutHeader('Uri');
                    $request = $request->withHeader('Uri', $state['uri']);

                    ProxyManager::handleClientConnection($connection, $request);
                }
            });
        });

    }

    public function validate($request)
    {
        $domain = $request->getHeaderLine('Domain');

        if (isset(ProxyManager::$uriToToken[$domain])) {
            if (ProxyManager::$uriToToken[$domain] != $request->getHeaderLine('Authorization')) {
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
