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
use Wpjscc\Penetration\Helper;
use Ramsey\Uuid\Uuid;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\P2pTunnel;
use Wpjscc\Penetration\Utils\Ip;

class Tunnel implements \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    protected $config;

    public $protocol = 'tcp';
    public $host = 'localhost';
    public $server80port;
    public $server443port;
    public $certPemPath = '';
    public $certKeyPath = '';

    public function __construct($config)
    {
        $this->config = $config;
        $host = $config['server_host'] ?? '';
        if ($host) {
            $this->host = $host;
        }
        $this->protocol = $config['tunnel_protocol'] ?? 'tcp';
        $this->server80port = $config['server_80_port'];
        $this->server443port = $config['server_443_port'] ?? '';
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
            $socket = new WebsocketTunnel($this->host, $this->server80port, '0.0.0.0', null, $context, $socket);
        } 
        else if ($protocol == 'wss') {
            if (!$this->certPemPath) {
                throw new \Exception('wss protocol must set cert_pem_path and cert_key_path');
            }

            if (!$this->server443port) {
                throw new \Exception('wss protocol must set server_443_port');
            }

            $socket = new WebsocketTunnel($this->host, $this->server443port, '0.0.0.0', null, $context, $socket);
        }
        
        else if ($protocol == 'udp') {
            $socket = new UdpTunnel('0.0.0.0:' . $this->server80port, null , function($server, $tunnel) {
                // $tunnel->supportKcp();

            } );
        }

        else if ($protocol == 'tls') {
            if (!$this->certPemPath) {
                throw new \Exception('tls protocol must set cert_pem_path and cert_key_path');
            }

            if (!$this->server443port) {
                throw new \Exception('tls protocol must set server_443_port');
            }
            
            $socket = new TcpTunnel('tls://0.0.0.0:' . $this->server443port, $context);
        } 
        else {
            $socket = new TcpTunnel('0.0.0.0:' . $this->server80port, $context);
        }
        return $socket;
    }

    public function run()
    {
        $protocols = explode(',', $this->protocol);

        foreach ($protocols as $protocol) {

            // 复用 80 和 443 端口
            if ($protocol == 'ws' || $protocol == 'wss') {
                continue;
            }

            if (!in_array($protocol, ['tls', 'tcp', 'udp'])) {
                continue;
            }

            $this->listenTunnel($protocol, $this->getTunnel($protocol));

            if ($protocol == 'tls') {
                static::getLogger()->notice("Client Server is running at {$protocol}:{$this->server443port}...");
            }
            else if ($protocol == 'tcp') {
                static::getLogger()->notice("Client Server is running at {$protocol}:{$this->server80port}...");
            }
            else if ($protocol == 'udp') {
                static::getLogger()->notice("Client Server is running at {$protocol}:{$this->server80port}...");
            }
            
        }
    }

    protected function listenTunnel($protocol, $socket)
    {
        $socket->on('connection', function (ConnectionInterface $connection) use ($protocol, $socket) {

            $ipWhiteList = $this->config['ip_whitelist'] ?? '';
            $ipBlackList = $this->config['ip_blacklist'] ?? '';
            $address = $connection->getRemoteAddress();
            if (!Ip::addressInIpWhitelist($address, $ipWhiteList) || Ip::addressInIpBlacklist($address, $ipBlackList)) {
                static::getLogger()->error("client: {$protocol} ip is unauthorized ", [
                    'remoteAddress' => $connection->getRemoteAddress(),
                ]);
                $headers = [
                    'HTTP/1.1 401 Unauthorized',
                    'Server: ReactPHP/1',
                    'Msg: ip is not in whitelist'
                ];
                $connection->write(implode("\r\n", $headers) . "\r\n\r\n");
                $connection->end();
                return;
            }


            // if ($protocol == 'udp') {
            //     static::getLogger()->error("client: {$protocol} is connected ", [
            //         'remoteAddress' => $connection->getRemoteAddress(),
            //     ]);
            //     (new P2pTunnel)->overConnection($connection);
            // }

            static::getLogger()->notice("client: {$protocol} is connected ", [
                'remoteAddress' => $connection->getRemoteAddress(),
            ]);

            $buffer = '';
            $that = $this;
            $connection->on('data', $fn = function ($chunk) use ($connection, &$buffer, &$fn, $that, $protocol, $socket) {
                static::getLogger()->notice("client: {$protocol} is data ", [
                    'remoteAddress' => $connection->getRemoteAddress(),
                    'length' => strlen($chunk),
                ]);
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
                        static::getLogger()->error($e->getMessage(), [
                            'class' => __CLASS__,
                        ]);
                        $buffer = '';
                        $connection->write($e->getMessage());
                        $connection->end();
                        return;
                    }

                    // websocket协议
                    if ($protocol == 'tcp' || $protocol == 'tls') {
                        $upgradeHeader = $request->getHeader('Upgrade');
                        if ((1 === count($upgradeHeader) && 'websocket' === strtolower($upgradeHeader[0]))) {
                            static::getLogger()->notice("client: {$protocol} is upgrade to websocket ", [
                                'remoteAddress' => $connection->getRemoteAddress(),
                            ]);
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
                        static::getLogger()->error($th->getMessage(), [
                            'class' => __CLASS__,
                            'file' => $th->getFile(),
                            'line' => $th->getLine(),
                        ]);
                    }

                    if (!$state) {
                        static::getLogger()->error("client: {$protocol} is unauthorized ", [
                            'request' => Helper::toString($request)
                        ]);
                        $headers = [
                            'HTTP/1.1 401 Unauthorized',
                            'Server: ReactPHP/1',
                        ];
                        $connection->write(implode("\r\n", $headers) . "\r\n\r\n");
                        $connection->end();
                        return;
                    }

                    $uuid = Uuid::uuid4()->toString();

                    static::getLogger()->notice("client: {$protocol} is authorized ", [
                        'uuid' => $uuid,
                        'request' => Helper::toString($request)
                    ]);

                    $headers = [
                        'HTTP/1.1 200 OK',
                        'Server: ReactPHP/1',
                        'Uuid: '. $uuid,
                        'Uri: ' . $state['uri'],
                    ];
                    $connection->write(implode("\r\n", $headers) . "\r\n\r\n");


                    $request = $request->withoutHeader('Uri');
                    $request = $request->withHeader('Uri', $state['uri']);

                    ProxyManager::handleClientConnection($connection, $request, $uuid);
                }
            });
        });

    }

    public function validate($request)
    {
        $domain = $request->getHeaderLine('Domain');
        $isPrivate = $request->getHeaderLine('Is-Private');

        $uris = explode(',', $domain);

        foreach ($uris as $uri) {
            if (isset(ProxyManager::$uriToInfo[$uri])) {
                if (ProxyManager::$uriToInfo[$uri]['token'] != $request->getHeaderLine('Authorization')) {
                    return false;
                }
            } else {
                ProxyManager::$uriToInfo[$uri]['token'] = $request->getHeaderLine('Authorization');
                ProxyManager::$uriToInfo[$uri]['is_private'] = $isPrivate ? true : false;
            }
        }


        return [
            'uri' => $request->getHeaderLine('Domain'),
        ];
    }
}
