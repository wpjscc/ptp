<?php

namespace Wpjscc\Penetration\Proxy;

use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\SingleTunnel;
use Ramsey\Uuid\Uuid;
use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Helper;
use Wpjscc\Penetration\Utils\PingPong;
use Wpjscc\Penetration\Utils\Ip;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\P2pTunnel;

class ProxyManager implements \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    public static $proxyConnections = [];

    public static function createConnection($uri)
    {
        return new ProxyConnection($uri, [
            'max_connections' => 1000,
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
                        if (in_array(($tunnelConnection->protocol ?? ''), ['p2p-udp', 'p2p-tcp'])) {
                            static::getLogger()->notice("send create dynamic connection by p2p {$tunnelConnection->protocol}  single tunnel", [
                                'uri' => $uri,
                                'uuid' => $uuid,
                                'remote_address' => $tunnelConnection->getRemoteAddress(),
                            ]);
                            $uuid = Uuid::uuid4()->toString();
                            $data = "HTTP/1.1 310 OK\r\nUuid:{$uuid}" . "\r\n\r\n";
                            $data = base64_encode($data);
                            // 通知客户端创建一个单通道
                            $tunnelConnection->write("HTTP/1.1 201 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
                        } else {
                            static::getLogger()->notice('send create dynamic connection by single tunnel', [
                                'uri' => $uri,
                                'uuid' => $uuid,
                                'remote_address' => $tunnelConnection->getRemoteAddress(),
                            ]);
                            // 通知客户端创建一个单通道
                            $tunnelConnection->write("HTTP/1.1 310 OK\r\nUuid:{$uuid}" . "\r\n\r\n");
                        }
                    } else if (in_array(($tunnelConnection->protocol ?? ''), ['p2p-udp', 'p2p-tcp'])) {
                        static::getLogger()->notice("send create dynamic connection by p2p {$tunnelConnection->protocol} single tunnel11", [
                            'uri' => $uri,
                            'uuid' => $uuid,
                            'remote_address' => $tunnelConnection->getRemoteAddress(),
                        ]);
                        $uuid = Uuid::uuid4()->toString();
                        $data = "HTTP/1.1 310 OK\r\nUuid:{$uuid}" . "\r\n\r\n";
                        $data = base64_encode($data);
                        // 通知客户端创建一个单通道
                        $tunnelConnection->write("HTTP/1.1 201 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
                    } else {
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

                // todo 最大数量限制
                static::$remoteTunnelConnections[$_uri]->attach($connection, [
                    'Single-Tunnel' => $request->getHeaderLine('Single-Tunnel'),
                    'Local-Host' => $request->getHeaderLine('Local-Host'),
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

            // 广播地址用的，和上方的单通道不冲突
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

    public static function pipe($connection, $request, $buffer = '', $callback = null)
    {
        $host = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();
        echo "host is $host\n";
       
        $uri = Ip::getUri($host, $port, $connection->protocol ?? '');

        echo "pipe uri is $uri\n";

        $proxyConnection = ProxyManager::getProxyConnection($uri);
        if ($proxyConnection === false) {
            if (\Wpjscc\Penetration\Environment::$type == 'client') {
                static::pipeRemote($connection, $request, $buffer);
            } else {
                static::getLogger()->warning('no proxy connection', [
                    'uri' => $uri,
                ]);
                $buffer = '';
                $content = "no proxy connection for $uri";
                $headers = [
                    'HTTP/1.1 200 OK',
                    'Server: ReactPHP/1',
                    'Content-Type: text/plain; charset=utf-8',
                    'Content-Length: '.strlen($content),
                ];
                $connection->write(implode("\r\n", $headers)."\r\n\r\n".$content);
                $connection->end();
            }
            
        } else {
            echo 'user: '.$uri.' is arive'."\n";
            if ($callback) {
                call_user_func($callback, $proxyConnection);
            }

            if (ProxyManager::$uriToInfo[$uri]['is_private']) {
                $hadTokens = ProxyManager::$uriToInfo[$uri]['tokens'];
                $tokens = array_values(array_filter(explode(',', $request->getHeaderLine('Proxy-Authorization'))));
                if (empty(array_intersect($hadTokens, $tokens))) {
                    $buffer = '';
                    $content = "Proxy Authorization is Failed\n";
                    $headers = [
                        'HTTP/1.1 200 OK',
                        'Server: ReactPHP/1',
                        'Content-Type: text/html; charset=UTF-8',
                        'Content-Length: '.strlen($content),
                    ];
                    $connection->write(implode("\r\n", $headers)."\r\n\r\n".$content);
                    $connection->end();
                    return;
                }
            }
           

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
            if (!isset(ClientManager::$visitUriToInfo[$uri]['tokens']) || empty(ClientManager::$visitUriToInfo[$uri]['tokens'])) {
                $buffer = '';
                $content = "local no $uri service, try pipe remote failed, no config for $uri";
                $headers = [
                    'HTTP/1.1 200 OK',
                    'Server: ReactPHP/1',
                    'Content-Type: text/plain; charset=utf-8',
                    'Content-Length: '.strlen($content),
                ];
                $connection->write(implode("\r\n", $headers)."\r\n\r\n".$content);
                $connection->end();
                return;
            }

            $connection->on('data', $fn =function($chunk) use (&$buffer) {
                $buffer .= $chunk;
            });

            (new \Wpjscc\Penetration\Tunnel\Local\Tunnel\TcpTunnel([
                'local_host' => $host,
                'local_port' => $port,
                'local_proxy' => ClientManager::$visitUriToInfo[$uri]['remote_proxy'],
                'token' => implode(',', ClientManager::$visitUriToInfo[$uri]['tokens']),
                'timeout' => 1,
            ]))->connect($uri)->then(function ($proxyConnection) use ($connection, $request, &$buffer, $fn, $uri) {
                static::getLogger()->debug('pipe remote success', [
                    'uri' => $uri,
                ]);
                $connection->removeListener('data', $fn);
                $proxyConnection->pipe($connection);
                $connection->pipe($proxyConnection);

                if ($buffer) {
                    $proxyConnection->write($buffer);
                    $buffer = '';
                }

                // $connection->write("HTTP/1.1 200 OK\r\nServer: ReactPHP/1\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: 39\r\n\r\nno   111proxy connection for email.test");
            }, function ($e) use ($connection, $request, &$buffer) {
                $buffer = '';
                $content = $e->getMessage(). " no proxy connection-2";
                $headers = [
                    'HTTP/1.1 200 OK',
                    'Server: ReactPHP/1',
                    'Content-Type: text/plain; charset=utf-8',
                    'Content-Length: '.strlen($content),
                ];
                $connection->write(implode("\r\n", $headers)."\r\n\r\n".$content);
                $connection->end();
                return $e;
            })->otherwise(function ($e) use ($connection, $request, &$buffer) {
                $buffer = '';
                $content = $e->getMessage(). " no proxy connection-3";
                $headers = [
                    'HTTP/1.1 200 OK',
                    'Server: ReactPHP/1',
                    'Content-Type: text/plain; charset=utf-8',
                    'Content-Length: '.strlen($content),
                ];

                $connection->write(implode("\r\n", $headers)."\r\n\r\n".$content);
                $connection->end();
                return $e;

            });
        }
    }
}
