<?php

namespace Wpjscc\Penetration\Proxy;

use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\SingleTunnel;
use Ramsey\Uuid\Uuid;
use Wpjscc\Penetration\Helper;

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
        if (isset(static::$proxyConnections[$uri])) {
            return static::$proxyConnections[$uri];
        }
        return static::$proxyConnections[$uri] = static::createConnection($uri);
    }



    // 通道道连接和代理连接

    public static $remoteTunnelConnections = [];

    public static $remoteDynamicConnections = [];

    public static $uriToToken = [];


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
            $index = rand(0, static::$remoteTunnelConnections[$uri]->count()-1);

            static::getLogger()->notice('random tunnel index', [
                'uri' => $uri,
                'index' => $index,
            ]);

            foreach (static::$remoteTunnelConnections[$uri] as $key=>$tunnelConnection) {
                if ($key === $index){
                    $singleTunnel = static::$remoteTunnelConnections[$uri][$tunnelConnection]['Single-Tunnel'] ?? false;
                    $uuid = Uuid::uuid4()->toString();
                    if ($singleTunnel) {
                        static::getLogger()->notice('send create dynamic connection by single tunnel', [
                            'uri' => $uri,
                            'uuid' => $uuid,
                            'remote_address' => $tunnelConnection->getRemoteAddress(),
                        ]);
                        // 通知客户端创建一个单通道
                        $tunnelConnection->write("HTTP/1.1 310 OK\r\nUuid:{$uuid}"."\r\n\r\n");
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
            static::getLogger()->warning('no tunnel connection', [
                'uri' => $uri,
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

        // 是通道
        if (!isset(static::$remoteTunnelConnections[$uri]) || $request->hasHeader('Tunnel')) {
            static::getLogger()->notice('add tunnel connection', [
                'uuid' => $uuid,
                'uri' => $uri,
                'request' => Helper::toString($request)
            ]);

            if (!isset(static::$remoteTunnelConnections[$uri])) {
                static::$remoteTunnelConnections[$uri] = new \SplObjectStorage;
            }

            // todo 最大数量限制
            static::$remoteTunnelConnections[$uri]->attach($connection, [
                'Single-Tunnel' => $request->getHeaderLine('Single-Tunnel'),
                'Local-Host' => $request->getHeaderLine('Local-Host'),
                'Uuid' => $uuid,
            ]);

            $ping = function ($connection) {
                $connection->write("HTTP/1.1 300 OK\r\n\r\n");
            };

            $pong = function ($connection) use ($request) {
                $deferred = new Deferred();

                $connection->once('data', $fn = function ($buffer) use ($deferred) {
                    if (strpos($buffer, 'HTTP/1.1 301 OK') !== false) {
                        $deferred->resolve();
                    }
                });

                \React\Promise\Timer\timeout($deferred->promise(), 3)->then(null, function ($e) use ($connection, $fn, $deferred) {
                    $connection->removeListener('data', $fn);
                    if ($e instanceof TimeoutException) {
                        $e =  new \RuntimeException(
                            'ping wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)',
                            \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                        );
                    }
                    $deferred->reject($e);
                });

                return $deferred->promise();
            };

            $timer = \React\EventLoop\Loop::addPeriodicTimer(10, function () use ($ping, $pong, $connection, $request, $uuid) {
                echo ("start ping pong".$connection->getRemoteAddress()."\n");
                $ping($connection);
                $pong($connection)->then(function () use ($request, $uuid) {
                    echo ("ping pong success\n\n");
                    static::getLogger()->info('ping pong success', [
                        'class' => __CLASS__,
                        'uri' => $request->getHeaderLine('Uri'),
                        'uuid' => $uuid,

                    ]);
                }, function ($e) use ($connection,$request, $uuid) {
                    static::getLogger()->error($e->getMessage(), [
                        'class' => __CLASS__,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'uri' => $request->getHeaderLine('Uri'),
                        'uuid' => $uuid,
                    ]);
                    $connection->close();
                });
            });

            $connection->on('close', function () use ($uri, $connection, $timer, $request, $uuid) {
                \React\EventLoop\Loop::cancelTimer($timer);
                static::$remoteTunnelConnections[$uri]->detach($connection);
                static::getLogger()->notice('remove tunnel connection', [
                    'uri' => $request->getHeaderLine('Uri'),
                    'uuid' => $uuid,
                ]);
                if (static::$remoteTunnelConnections[$uri]->count() == 0) {
                    unset(static::$remoteTunnelConnections[$uri]);
                    unset(static::$uriToToken[$uri]);
                }
            });


            if ($request->getHeaderLine('Single-Tunnel')) {
                static::getLogger()->notice('create single tunnel', [
                    'uri' => $request->getHeaderLine('Uri'),
                    'uuid' => $uuid,
                ]);

                $singleTunnel = new SingleTunnel();
                $singleTunnel->overConnection($connection);
                $singleTunnel->on('connection', function ($singleConnection) use ($connection, $uri, $request, $uuid) {
                    if (isset(static::$remoteDynamicConnections[$uri]) && static::$remoteDynamicConnections[$uri]->count() > 0) {
                        static::getLogger()->notice('add dynamic connection by single tunnel', [
                            'uri' => $request->getHeaderLine('Uri'),
                            'uuid' => $uuid,
                            'remote_address' => $singleConnection->getRemoteAddress(),
                        ]);
                        static::$remoteDynamicConnections[$uri]->rewind();
                        $deferred = static::$remoteDynamicConnections[$uri]->current();
                        static::$remoteDynamicConnections[$uri]->detach($deferred);
                        static::getLogger()->notice('deferred dynamic connection single-tunnel', [
                            'uri' => $request->getHeaderLine('Uri'),
                            'uuid' => $uuid,
                            'remote_address' => $singleConnection->getRemoteAddress(),
                        ]);
                        $singleConnection->tunnelConnection = $connection;
                        $deferred->resolve($singleConnection);
                    } else {
                        echo ("no dynamic connection by single tunnel".$singleConnection->getRemoteAddress()."\n");
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

            return ;
        }

        static::getLogger()->notice('dynamic is arriving', [
            'uri' => $request->getHeaderLine('Uri'),
            'uuid' => $request->getHeaderLine('Uuid'),
            'remote_address' => $connection->getRemoteAddress(),
        ]);

        // todo 最大数量限制
        // 其次请求
        if (isset(static::$remoteDynamicConnections[$uri]) && static::$remoteDynamicConnections[$uri]->count() > 0) {
            echo ("add dynamic connection ".$connection->getRemoteAddress()."\n");
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
         
            return ;
        }
        $connection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
        $connection->end();
    }
}