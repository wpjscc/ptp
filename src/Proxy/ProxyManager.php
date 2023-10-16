<?php

namespace Wpjscc\PTP\Proxy;

use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use Wpjscc\PTP\Tunnel\Server\Tunnel\SingleTunnel;
use Ramsey\Uuid\Uuid;
use Wpjscc\PTP\Client\ClientManager;
use Wpjscc\PTP\Client\VisitUriManager;
use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Config;
use Wpjscc\PTP\Utils\PingPong;
use Wpjscc\PTP\Utils\Ip;
use Wpjscc\PTP\Tunnel\Server\Tunnel\P2pTunnel;

class ProxyManager implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    public static $proxyConnections = [];

    public static function createConnection($uri)
    {
        // todo by uri set config
        return new ProxyConnection($uri, [
            'max_connections' => 25,
            'max_wait_queue' => 50,
            'wait_timeout' => 10,
        ]);
    }

    public static function getProxyConnection($uri)
    {

        if (!isset(static::$remoteTunnelConnections[$uri])) {
            return false;
        }

        if (isset(static::$proxyConnections[$uri])) {
            return static::$proxyConnections[$uri];
        }
        return static::$proxyConnections[$uri] = static::createConnection($uri);
    }



    // 通道道连接和代理连接

    public static $remoteTunnelConnections = [];

    public static $remoteDynamicConnections = [];

    public static $uriToInfo = [];


    public static function createRemoteDynamicConnection($uri)
    {

        $deferred = new Deferred();

        static::getLogger()->notice('start create remote dynamic connection', [
            'uri' => $uri,
        ]);


        if (isset(static::$remoteTunnelConnections[$uri]) && static::$remoteTunnelConnections[$uri]->count() > 0) {

            static::getLogger()->notice('create dynamic connection', [
                'uri' => $uri,
                'tunnel_count' => static::$remoteTunnelConnections[$uri]->count(),
            ]);

            // 随机发送一个创建链接的请求(给他通道发送)
            $index = rand(0, static::$remoteTunnelConnections[$uri]->count() - 1);

            static::getLogger()->notice('random tunnel index', [
                'uri' => $uri,
                'index' => $index,
            ]);

            foreach (static::$remoteTunnelConnections[$uri] as $key => $tunnelConnection) {
                if ($key === $index) {
                    $singleTunnel = static::$remoteTunnelConnections[$uri][$tunnelConnection]['Single-Tunnel'] ?? false;
                    $uuid = Uuid::uuid4()->toString();
                    if ($singleTunnel) {
                        // 客户端运行
                        if (in_array(($tunnelConnection->protocol ?? ''), ['p2p-udp', 'p2p-tcp'])) {
                            static::getLogger()->notice("send create dynamic connection by p2p {$tunnelConnection->protocol}  single tunnel", [
                                'uri' => $uri,
                                'uuid' => $uuid,
                                'remote_address' => $tunnelConnection->getRemoteAddress(),
                            ]);
                            $uuid = Uuid::uuid4()->toString();
                            $data = "HTTP/1.1 310 OK\r\nUuid:{$uuid}\r\nUri: {$uri}" . "\r\n\r\n";
                            $data = base64_encode($data);
                            // 通知客户端创建一个单通道
                            $tunnelConnection->write("HTTP/1.1 201 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
                        }
                        // 服务端 
                        else {
                            static::getLogger()->notice('send create dynamic connection by single tunnel', [
                                'uri' => $uri,
                                'uuid' => $uuid,
                                'remote_address' => $tunnelConnection->getRemoteAddress(),
                            ]);
                            // 通知客户端创建一个单通道
                            $tunnelConnection->write("HTTP/1.1 310 OK\r\nUuid:{$uuid}\r\nUri: {$uri}" . "\r\n\r\n");
                        }
                    } 
                    // 客户端运行
                    else if (in_array(($tunnelConnection->protocol ?? ''), ['p2p-udp', 'p2p-tcp'])) {
                        static::getLogger()->notice("send create dynamic connection by p2p {$tunnelConnection->protocol} single tunnel11", [
                            'uri' => $uri,
                            'uuid' => $uuid,
                            'remote_address' => $tunnelConnection->getRemoteAddress(),
                        ]);
                        $uuid = Uuid::uuid4()->toString();
                        $data = "HTTP/1.1 310 OK\r\nUuid:{$uuid}\r\nUri: {$uri}" . "\r\n\r\n";
                        $data = base64_encode($data);
                        // 通知对端开始接受请求
                        $tunnelConnection->write("HTTP/1.1 201 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
                    }
                    // 服务端运行 
                    else {
                        static::getLogger()->notice('send create dynamic connection', [
                            'uri' => $uri,
                            'remote_address' => $tunnelConnection->getRemoteAddress(),
                        ]);
                        // 通知客户端发起一个动态连接请求
                        $tunnelConnection->write("HTTP/1.1 201 OK\r\n\r\n");
                    }

                    break;
                }
            }
        } else {
            static::getLogger()->error('no tunnel connection', [
                'uri' => $uri,
                'uris' => array_keys(static::$remoteTunnelConnections),
            ]);
            return \React\Promise\reject(new \Exception('no tunnel connection, please try again later'));
        }

        if (!isset(static::$remoteDynamicConnections[$uri])) {
            static::$remoteDynamicConnections[$uri] = new \SplObjectStorage;
        }

        static::$remoteDynamicConnections[$uri]->attach($deferred);

        return \React\Promise\Timer\timeout($deferred->promise(), 3)->then(null, function ($e) use ($uri, $deferred) {

            static::$remoteDynamicConnections[$uri]->detach($deferred);

            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'remote dynamic tunnel connection  wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                );
            }
            throw $e;
        });
    }

    public static function handleClientConnection($connection, $request, $uuid)
    {

        $uri = $request->getHeaderLine('Uri');

        // $uuid 服务端生成的
        $uris = array_values(array_filter(explode(',', $uri)));

        // 是通道
        if ($request->hasHeader('Tunnel')) {
            // foreach ($uris as $key1 => $uri) {
            //     // 域名
            //     if (strpos($uri, ':') === false) {
                   
            //     }
            //     // ip  
            //     else {
            //         // 没有指定协议的
            //         if (strpos('://', $uri) === false) {
            //             array_push($uris, 'tcp://' . $uri);   
            //             array_push($uris, 'udp://' . $uri);   
            //         }

            //     }
            // }
            
            // 支持多domain
            foreach ($uris as $key => $_uri) {
                static::getLogger()->notice('add tunnel connection', [
                    'uuid' => $uuid,
                    'uri' => $_uri,
                    'request' => Helper::toString($request)
                ]);

                if (!isset(static::$remoteTunnelConnections[$_uri])) {
                    static::$remoteTunnelConnections[$_uri] = new \SplObjectStorage;
                }

                if (static::$remoteTunnelConnections[$_uri]->count() >= \Wpjscc\PTP\Config::getKey('common.max_tunnel_number', 5)) {
                    static::getLogger()->error('tunnel connection count is more than 5', [
                        'uri' => $_uri,
                        'uuid' => $uuid,
                    ]);
                    $connection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
                    $connection->end();
                    return;
                }

                static::$remoteTunnelConnections[$_uri]->attach($connection, [
                    'Single-Tunnel' => $request->getHeaderLine('Single-Tunnel'),
                    'Local-Host' => $request->getHeaderLine('Local-Host'),
                    'Local-Protocol' => $request->getHeaderLine('Local-Protocol'),
                    'Local-Replace-Host' => $request->getHeaderLine('Local-Replace-Host'),
                    'Uuid' => $uuid,
                ]);
                $connection->on('close', function () use ($_uri, $connection, $request, $uuid) {
                    static::$remoteTunnelConnections[$_uri]->detach($connection);
                    static::getLogger()->notice('remove tunnel connection', [
                        'uri' => $request->getHeaderLine('Uri'),
                        'uuid' => $uuid,
                    ]);
                    if (static::$remoteTunnelConnections[$_uri]->count() == 0) {
                        unset(static::$remoteTunnelConnections[$_uri]);
                        unset(static::$uriToInfo[$_uri]);
                    }
                });
            }


            if ($request->getHeaderLine('Single-Tunnel')) {
                static::getLogger()->notice('create single tunnel', [
                    'uri' => $request->getHeaderLine('Uri'),
                    'uuid' => $uuid,
                ]);

                $singleTunnel = new SingleTunnel();
                $singleTunnel->overConnection($connection);
                $singleTunnel->on('connection', function ($singleConnection) use ($connection, $request, $uuid, $uris) {
                    $isExist = false;
                    foreach ($uris as $key => $uri) {
                        // 在等待建立通道的连接，如果有就建立
                        if (isset(static::$remoteDynamicConnections[$uri]) && static::$remoteDynamicConnections[$uri]->count() > 0) {
                            $isExist = true;
                            static::getLogger()->notice('add dynamic connection by single tunnel', [
                                'uri' => $uri,
                                'uuid' => $uuid,
                                'remote_address' => $singleConnection->getRemoteAddress(),
                            ]);
                            static::$remoteDynamicConnections[$uri]->rewind();
                            $deferred = static::$remoteDynamicConnections[$uri]->current();
                            static::$remoteDynamicConnections[$uri]->detach($deferred);
                            static::getLogger()->notice('deferred dynamic connection single-tunnel', [
                                'uri' => $uri,
                                'uuid' => $uuid,
                                'remote_address' => $singleConnection->getRemoteAddress(),
                            ]);
                            $singleConnection->tunnelConnection = $connection;
                            $deferred->resolve($singleConnection);
                        }
                    }

                    // 一个也没有说明用户访问端可能过早关闭了
                    if (!$isExist) {
                        echo ("no dynamic connection by single tunnel" . $singleConnection->getRemoteAddress() . "\n");
                        static::getLogger()->notice('no dynamic connection by single tunnel', [
                            'uri' => $request->getHeaderLine('Uri'),
                            'uuid' => $uuid,
                            'remote_address' => $singleConnection->getRemoteAddress(),
                        ]);
                        $singleConnection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
                        $singleConnection->end();
                    }
                });
            }

            // p2p 广播地址用的，和上方的单通道不冲突
            if (($connection->protocol ?? '') === 'udp') {
                if ($request->getHeaderLine('Is-P2p')) {
                    static::getLogger()->notice('create p2p tunnel', [
                        'uri' => $request->getHeaderLine('Uri'),
                        'uuid' => $uuid,
                    ]);
                    (new P2pTunnel)->overConnection($connection);
                }
            }
            PingPong::pingPong($connection, $connection->getRemoteAddress());
            return;
        }

        static::getLogger()->notice('dynamic is arriving', [
            'uri' => $request->getHeaderLine('Uri'),
            'uuid' => $request->getHeaderLine('Uuid'),
            'remote_address' => $connection->getRemoteAddress(),
        ]);

        $isExist = false;
        foreach ($uris as $uri) {
            // 在等待建立通道连接
            if (isset(static::$remoteDynamicConnections[$uri]) && static::$remoteDynamicConnections[$uri]->count() > 0) {
                echo ("add dynamic connection " . $connection->getRemoteAddress() . "\n");
                static::getLogger()->notice('add dynamic connection', [
                    'uri' => $request->getHeaderLine('Uri'),
                    'uuid' => $request->getHeaderLine('Uuid'),
                    'remote_address' => $connection->getRemoteAddress(),
                ]);
                // parent uuid
                $uuid = $request->getHeaderLine('Uuid');
                $remoteTunnelConnection = null;
                foreach (static::$remoteTunnelConnections[$uri] as $tunnelConnection) {
                    if (static::$remoteTunnelConnections[$uri][$tunnelConnection]['Uuid'] == $uuid) {
                        $remoteTunnelConnection = $tunnelConnection;
                        break;
                    }
                }

                static::$remoteDynamicConnections[$uri]->rewind();
                $deferred = static::$remoteDynamicConnections[$uri]->current();
                static::$remoteDynamicConnections[$uri]->detach($deferred);
                static::getLogger()->notice('deferred dynamic connection', [
                    'uri' => $request->getHeaderLine('Uri'),
                    'uuid' => $request->getHeaderLine('Uuid'),
                    'remote_address' => $connection->getRemoteAddress(),
                ]);
                if (empty($remoteTunnelConnection)) {
                    static::getLogger()->error('tunnel connection not found by uuid', [
                        'uri' => $request->getHeaderLine('Uri'),
                        'uuid' => $request->getHeaderLine('Uuid'),
                        'remote_address' => $connection->getRemoteAddress(),
                    ]);
                    $deferred->reject(new \Exception('tunnel connection not found by uuid, please try again later'));
                } else {
                    $connection->tunnelConnection = $remoteTunnelConnection;
                    $deferred->resolve($connection);
                }
                $isExist = true;
                break;
            }
        }

        if (!$isExist) {
            $connection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
            $connection->end();
        }


    }

    public static function pipe($connection, $request, $buffer = '')
    {
        $host = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();
        echo "host is $host\n";
       
        $uri = Ip::getUri($host, $port, $connection->protocol ?? '');

        echo "pipe uri is $uri\n";

        $proxyConnection = false;

        // 本地访问的是ip, 去看下是否有p2p-tcp 通道
        if (\Wpjscc\PTP\Environment::$type == 'client') {
            if (Ip::isIp($host)){
                // 本地的要么是tcp要么是udp
                if ($connection->protocol == 'tcp') {
                    // 这里的uri是 ip:port
                    if (strpos('://', $uri) === false) {
                        // 去看下是否有p2p-tcp通道
                        static::getLogger()->notice('try get p2p-tcp connection', [
                            'uri' => $uri,
                        ]);
                        $proxyConnection = ProxyManager::getProxyConnection('tcp://'. $uri);
                        if ($proxyConnection) {
                            $uri = 'tcp://'. $uri;
                        }
                    }
                } else {
                    // 说明是udp
                    // 这里的uri 是 udp://ip:port
                    // 不做处理
                }
            }
        }

        if ($proxyConnection === false) {
            $proxyConnection = ProxyManager::getProxyConnection($uri);
        } else {
            static::getLogger()->notice('get p2p-tcp connection success', [
                'uri' => $uri,
            ]);
        }
  

        if ($proxyConnection === false) {
            if (\Wpjscc\PTP\Environment::$type == 'client') {
                static::pipeRemote($connection, $request, $buffer);
            } else {
                static::getLogger()->warning('no proxy connection', [
                    'uri' => $uri,
                ]);
                $buffer = '';
                static::endConnection($connection, "no proxy connection for $uri");
            }
            
        } else {
            echo 'user: '.$uri.' is arive'."\n";

            // 在服务端验证
            // 验证token（在服务端）点对点通信 with server
            if (ProxyManager::$uriToInfo[$uri]['is_private'] ?? false) {
                $hadTokens = ProxyManager::$uriToInfo[$uri]['tokens'];
                $tokens = array_values(array_filter(explode(',', $request->getHeaderLine('Proxy-Authorization'))));
                if (empty(array_intersect($hadTokens, $tokens))) {
                    $buffer = '';
                    static::endConnection($connection, "Proxy Authorization is Failed");
                    return;
                }
            }

            // 在服务端验证
            if ((ProxyManager::$uriToInfo[$uri]['http_user'] ?? '') && (ProxyManager::$uriToInfo[$uri]['http_pwd'] ?? '')) {
                static::getLogger()->debug('Authenticate', [
                    'uri' => $uri,
                    'host' => $host,
                    'port' => $port,
                    'request' => Helper::toString($request)
                ]);
                $auth = $request->getHeaderLine('Authorization');
                if (!$auth) {
                    $connection->end(implode("\r\n",[
                        "HTTP/1.1 401 Invalid credentials",
                        "WWW-Authenticate: Basic",
                        "\r\n"
                    ]));
                    return;
                }

                $auth = explode(' ', $auth);
                if (count($auth) != 2 || $auth[0] != 'Basic') {
                    $connection->end(implode("\r\n", [
                        "HTTP/1.1 401 Invalid credentials",
                        "WWW-Authenticate: Basic",
                        "\r\n"
                    ]));
                    return;
                }

                $auth = base64_decode($auth[1]);
                $auth = explode(':', $auth);
                if (count($auth) != 2 || $auth[0] != ProxyManager::$uriToInfo[$uri]['http_user'] || $auth[1] != ProxyManager::$uriToInfo[$uri]['http_pwd']) {
                    $connection->end(implode("\r\n",[
                        "HTTP/1.1 401 Invalid credentials",
                        "WWW-Authenticate: Basic",
                        "\r\n"
                    ]));
                    return;
                }
                
            }
            static::getLogger()->debug('Authenticate success', [
                'uri' => $uri,
                'host' => $host,
                'port' => $port,
                'proxy' => ProxyManager::$uriToInfo[$uri]['remote_proxy'] ?? '',
                'tokens' => ProxyManager::$uriToInfo[$uri]['tokens'] ?? [],
            ]);
            $proxyConnection->pipe($connection, $buffer, $request);
        }
    }

    // client -> server
    public static function pipeRemote($connection, $request, &$buffer = '')
    {
        $host = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();
        echo "pipeRemote host is $host\n";
       
        $uri = Ip::getUri($host, $port, $connection->protocol ?? '');

        echo "pipeRemote pipe uri is $uri\n";

        $proxyConnection = ProxyManager::getProxyConnection($uri);
        if ($proxyConnection === false) {
            // if (!isset(ClientManager::$visitUriToInfo[$uri])) {
            //     $buffer = '';
            //     static::endConnection($connection, "local no $uri service, try pipe remote failed, no config for $uri");
            //     return;
            // }

            $connection->on('data', $fn =function($chunk) use (&$buffer) {
                $buffer .= $chunk;
            });

            static::getLogger()->debug('pipe remote', [
                'uri' => $uri,
                'host' => $host,
                'port' => $port,
                'proxy' => VisitUriManager::getUriRemoteProxy($uri) ?? Config::getRemoteProxy(),
                'tokens' => VisitUriManager::getUriTokens($uri),
            ]);

            (new \Wpjscc\PTP\Tunnel\Local\Tunnel\TcpTunnel([
                'local_host' => $host,
                'local_port' => $port,
                'local_http_proxy' => VisitUriManager::getUriRemoteProxy($uri) ?? Config::getRemoteProxy(),
                'timeout' => 1,
            ], [
                'Proxy-Authorization' => implode(',', VisitUriManager::getUriTokens($uri)),
                'Authorization' => $request->getHeaderLine('Authorization'),
            ]))->connect($uri)->then(function ($proxyConnection) use ($connection, $request, &$buffer, $fn, $uri) {
                static::getLogger()->debug('pipe remote success', [
                    'uri' => $uri,
                ]);
                $connection->removeListener('data', $fn);

                // when fail has some problem see https://github.com/clue/reactphp-http-proxy/issues/52
                // $proxyConnection->pipe($connection);
                // $connection->pipe($proxyConnection);

                $proxyConnection->on('data', function ($chunk) use ($connection) {
                    // var_dump($chunk);
                    $connection->write($chunk);
                });
                $connection->on('data', function ($chunk) use ($proxyConnection) {
                    $proxyConnection->write($chunk);
                });

                $proxyConnection->on('close', function () use ($connection, $uri) {
                    static::getLogger()->debug('pipe remote close1', [
                        'uri' => $uri,
                    ]);
                    $connection->end();
                });

                $connection->on('close', function () use ($proxyConnection, $uri) {
                    static::getLogger()->debug('pipe remote close', [
                        'uri' => $uri,
                    ]);
                    $proxyConnection->close();
                });

                if ($buffer) {
                    $proxyConnection->write($buffer);
                    $buffer = '';
                }
            }, function ($e) use ($connection, &$buffer) {
                static::getLogger()->error('pipe remote failed-1', [
                    'error' => $e->getMessage(),
                ]);
                $buffer = '';
                static::endConnection($connection, $e->getMessage(). " no proxy connection-2");
                return $e;
            })->otherwise(function ($e) use ($connection, &$buffer) {
                static::getLogger()->error('pipe remote failed-2', [
                    'error' => $e->getMessage(),
                ]);
                $buffer = '';

                static::endConnection($connection, $e->getMessage(). " no proxy connection-3");
                return $e;

            });
        }
    }

    protected static function endConnection($connection, $content)
    {
        static::getLogger()->warning('end connection', [
            'content' => $content,
        ]);

        $connection->write(implode("\r\n",[
            'HTTP/1.1 200 OK',
            'Server: ReactPHP/1',
            'Content-Type: text/plain; charset=utf-8',
            // 'Content-Length: '.strlen($content),
            "\r\n",
            $content
        ]));
        $connection->end();
    }
}
