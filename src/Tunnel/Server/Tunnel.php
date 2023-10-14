<?php

namespace Wpjscc\Penetration\Tunnel\Server;

use React\Socket\ConnectionInterface;
use Wpjscc\Penetration\Proxy\ProxyManager;
use RingCentral\Psr7;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\TcpTunnel;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\UdpTunnel;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\WebsocketTunnel;
use Wpjscc\Penetration\DecorateSocket;
use Wpjscc\Penetration\Helper;
use Ramsey\Uuid\Uuid;
use Wpjscc\Penetration\Utils\Ip;

class Tunnel implements \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    protected $config;

    public $protocol = 'tcp';
    public $host = 'localhost';
    public $server80port;
    public $server443port;
    public $cert = [];
    public $certPemPath = '';
    public $certKeyPath = '';
    public $httpPortOverTunnelPort = '';

    public function __construct($config, $cert = [])
    {
        $this->cert = $cert;

        $this->config = $config;
        $host = $config['tunnel_host'] ?? '';
        if ($host) {
            $this->host = $host;
        }
        $this->protocol = $config['tunnel_protocol'] ?? 'tcp';
        $this->server80port = $config['tunnel_80_port'];
        $this->server443port = $config['tunnel_443_port'] ?? '';
        // $this->certPemPath = $config['cert_pem_path'] ?? '';
        // $this->certKeyPath = $config['cert_key_path'] ?? '';
        $this->httpPortOverTunnelPort = $config['http_port_over_tunnel_port'] ?? true;
    }

    public function getTunnel($protocol = null, $socket = null)
    {

        if (!$protocol) {
            $protocol = $this->protocol;
        }

        $context = [];

        if ($this->cert) {
            $context = [
                'tls' => array(
                    // 'local_cert' => $this->certPemPath,
                    // 'local_pk' => $this->certKeyPath,
                    'SNI_enabled' => true,
                    'SNI_server_certs' => $this->cert
                )
            ];
        }

        if ($protocol == 'ws') {
            $socket = new WebsocketTunnel($this->host, $this->server80port, '0.0.0.0', null, $context, $socket);
        } 
        else if ($protocol == 'wss') {
            if (!$this->cert) {
                throw new \Exception('wss protocol must set cert');
            }

            if (!$this->server443port) {
                throw new \Exception('wss protocol must set tunnel_443_port');
            }

            $socket = new WebsocketTunnel($this->host, $this->server443port, '0.0.0.0', null, $context, $socket);
        }
        
        else if ($protocol == 'udp') {
            $socket = new UdpTunnel('0.0.0.0:' . $this->server80port, null , function($server, $tunnel) {
                // $tunnel->supportKcp();

            } );
        }

        else if ($protocol == 'tls') {
            if (!$this->cert) {
                throw new \Exception('tls protocol must set cert');
            }

            if (!$this->server443port) {
                throw new \Exception('tls protocol must set tunnel_443_port');
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

            $address = $connection->getRemoteAddress();
            if (
                !Ip::addressInIpWhitelist($address, \Wpjscc\Penetration\Config::getKey('ip_whitelist', '')) 
                || Ip::addressInIpBlacklist($address, \Wpjscc\Penetration\Config::getKey('ip_blacklist', ''))
            ) {
                static::getLogger()->error("client: {$protocol} ip is unauthorized ", [
                    'remoteAddress' => $connection->getRemoteAddress(),
                ]);
                $connection->write(implode("\r\n", [
                    'HTTP/1.1 401 Unauthorized',
                    'Server: ReactPHP/1',
                    "\r\n",
                    "ip is not in whitelist"
                ]));
                $connection->end();
                return;
            }

            static::getLogger()->notice("client: {$protocol} is connected ", [
                'remoteAddress' => $connection->getRemoteAddress(),
            ]);

            $connection->on('error', function ($e) use ($connection, $protocol) {
                static::getLogger()->error("client: {$protocol} is error ", [
                    'remoteAddress' => $connection->getRemoteAddress(),
                    'error' => $e->getMessage(),
                ]);
            });

            $connection->on('close', function () use ($connection, $protocol) {
                static::getLogger()->warning("client: {$protocol} is close ", [
                    'remoteAddress' => $connection->getRemoteAddress(),
                ]);
            });

            $buffer = '';
            $first = true;
            $that = $this;
            $connection->on('data', $fn = function ($chunk) use ($connection, &$buffer, &$fn, $that, $protocol, $socket, &$first) {
                static::getLogger()->debug("client: {$protocol} is data ", [
                    'remoteAddress' => $connection->getRemoteAddress(),
                    'length' => strlen($chunk),
                ]);
                $buffer .= $chunk;

                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos !== false) {
                    // HTTP Proxy Request                                                |-----------------|
                    //                           tunnel Server                           |  tunnel pool    |
                    //                             |------by domain find service-->----> |                 |
                    //                             |  |----------<----<------------------|---------|-------|
                    //               http/s Proxy  |  |                                            |
                    //         +------>-->---------+--|-----------------+                          |
                    //         |                      |                 |                          |
                    //         |----------<----<------|                 |                          |
                    //         |                                        |                          |
                    //      Client A                                    Client B                   |
                    //                                                                             |
                    //                                                                             |
                    //                                                                             |
                    //                              Server                             |-----------|------|             |------------------|
                    //                                |-------------->------->---------|                  |             |                  |
                    //                                |                                |  tunnel pool     |------------>| local.test       |
                    //                                |                                |------------------|             |                  |
                    //                                |                                                                 | 192.168.1.1:3000 |--<---|
                    //                                |                                                                 | www.domain.com   |      |
                    //                                |                                                                 |------------------|      |
                    //         +----------register----+-----register---------+-----register------------+                                          |
                    //         |                                             |                         |                                          |
                    //         |                                             |                         |                                          |
                    //         |                                             |                         |                                          |
                    //      Client A                                      Client B local.test          Client C 192.168.1.1:3000,www.domain.com   |
                    //            |                                                                                                               |
                    //            |                                                                                                               |          
                    //            |                                          |-------|                                                            |  
                    //            |                                          |       |                                                            |      
                    //            |---by proxy can visit---------------------|Server |------------------------------------------------------------|
                    //                                                       |-------|
                    //
                    //
                    //
                    if ($first && (strpos($buffer, "CONNECT") === 0)) {
                        $connection->removeListener('data', $fn);
                        $fn = null;
                        try {
                            static::getLogger()->debug("CONNECTION DATA", [
                                'request' => $buffer,
                            ]);
                            $token = '';
                            $pattern = '/Proxy-Authorization: ([^\r\n]+)/i';
                            if (preg_match($pattern, $buffer, $matches1)) {
                                $token = $matches1[1];
                            }
                            $auth = '';
                            $authPattern = '/Authorization: (\S+) (\S+)/i';
                            if (preg_match($authPattern, $buffer, $matches2)) {
                                $auth = $matches2[1] . ' ' . $matches2[2];
                            }
                            $pattern = "/CONNECT ([^\s]+) HTTP\/(\d+\.\d+)/";
                            if (preg_match($pattern, $buffer, $matches)) {
                                $host = $matches[1];
                                $version = $matches[2];
                                $connection->write("HTTP/{$version} 200 Connection Established\r\n\r\n");
                                $request = Psr7\parse_request("GET /connect HTTP/1.1\r\nHost: $host\r\nProxy-Authorization: {$token}\r\nAuthorization: {$auth}\r\n\r\n");
                                ProxyManager::pipe($connection, $request);
                                $buffer = '';
                            } else {
                                $buffer = '';
                                $connection->write('Invalid request');
                                $connection->end();
                            }
                        } catch (\Exception $e) {
                            static::getLogger()->error($e->getMessage(), [
                                'class' => __CLASS__,
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                            ]);
                            $buffer = '';
                            $connection->write($e->getMessage());
                            $connection->end();
                        }
                        return;
                    }

                    $first = false;
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

                    // client upgrade tcp/tls to  ws/wss protocol
                    //                             Server
                    //                         
                    //                                |
                    //                                |
                    //         +-------wss/ws--->-----+----------------------+
                    //         |                                             |
                    //         |                                             |
                    //         |                                             |
                    //      Client A                                      Client B
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


                    // 验证 tunnel 和 dynamic tunnel
                    $state =  $that->validate($request);
                    if (!$state) {
                        if ($state === 0) {
                            // 转发 http request
                            if ($protocol == 'tcp' || $protocol == 'tls') {
                                if ($this->httpPortOverTunnelPort) {
                                    ProxyManager::pipe($connection, $request, $buffer);
                                    return;
                                }
                            }
                        }

                        static::getLogger()->error("client: {$protocol} is unauthorized ", [
                            'request' => Helper::toString($request)
                        ]);

                        $connection->write(implode("\r\n", [
                            'HTTP/1.1 401 Unauthorized',
                            'Server: ReactPHP/1',
                            "\r\n"
                        ]));
                        $connection->end();
                        return;
                    }
                    $buffer = '';


                    $uuid = Uuid::uuid4()->toString();

                    static::getLogger()->notice("client: {$protocol} is authorized ", [
                        'uuid' => $uuid,
                        'request' => Helper::toString($request)
                    ]);
                    // 告诉客户端验证通过了
                    //                           Server
                    //                         
                    //                                |
                    //                tunnel          |
                    //         +------<--<---200------+--------------------+
                    //         |    or dynamic tunnel                      |
                    //         |                                           |
                    //         |                                           |
                    //      Client                                                                   

                    $connection->write(implode("\r\n", [
                        'HTTP/1.1 200 OK',
                        'Server: ReactPHP/1',
                        'Uuid: '. $uuid,
                        'Uri: ' . $state['uri'],
                        "\r\n"
                    ]));

                    $request = $request->withoutHeader('Uri');
                    $request = $request->withHeader('Uri', $state['uri']);

                    ProxyManager::handleClientConnection($connection, $request, $uuid);
                }
            });
        });

    }

    public function validate($request)
    {
        if (!$request->getHeaderLine('X-Is-Ptp')) {
            return 0;
        }
        if (!Helper::validateSecretKey($request->getHeaderLine('Secret-Key'))) {
            return false;
        }

        $domain = $request->getHeaderLine('Domain');
        $isPrivate = $request->getHeaderLine('Is-Private');
        $httpUser = $request->getHeaderLine('Http-User');
        $httpPwd = $request->getHeaderLine('Http-Pwd');
        $tokens = array_values(array_filter(explode(',', $request->getHeaderLine('Authorization'))));

        $uris = explode(',', $domain);

        foreach ($uris as $uri) {
            if (isset(ProxyManager::$uriToInfo[$uri])) {
                $hadTokens = ProxyManager::$uriToInfo[$uri]['tokens'] ?? [];
                if (!empty($hadTokens) && empty(array_intersect($hadTokens, $tokens))) {
                    return false;
                }
            } else {
                ProxyManager::$uriToInfo[$uri]['tokens'] = $tokens;
                ProxyManager::$uriToInfo[$uri]['is_private'] = $isPrivate ? true : false;
                ProxyManager::$uriToInfo[$uri]['http_user'] = $httpUser;
                ProxyManager::$uriToInfo[$uri]['http_pwd'] = $httpPwd;
            }
        }


        return [
            'uri' => $request->getHeaderLine('Domain'),
        ];
    }
}
