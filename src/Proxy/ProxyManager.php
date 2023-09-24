<?php

namespace Wpjscc\Penetration\Proxy;

use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\SingleTunnel;
use Ramsey\Uuid\Uuid;


class ProxyManager
{
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
        echo 'start create dynamic connection'. $uri."\n";
        if (isset(static::$remoteTunnelConnections[$uri]) && static::$remoteTunnelConnections[$uri]->count() > 0) {
            echo ('create dynamic connection'."\n");
            $headers = [
                'HTTP/1.1 201 OK',
                'Uri: '.$uri
            ];

            echo ('remote tunnel connection count '.static::$remoteTunnelConnections[$uri]->count()."\n");

            // 随机发送一个创建链接的请求(给他通道发送)
            $index = rand(0, static::$remoteTunnelConnections[$uri]->count()-1);

            echo ('random index '.$index."\n");

            foreach (static::$remoteTunnelConnections[$uri] as $key=>$tunnelConnection) {
                if ($key === $index){
                    $singleTunnel = static::$remoteTunnelConnections[$uri][$tunnelConnection]['Single-Tunnel'] ?? false;
                    if ($singleTunnel) {
                        $uuid = Uuid::uuid4()->toString();
                        echo ("send create dynamic connection by single tunnel ".$tunnelConnection->getRemoteAddress()."\n");
                        $tunnelConnection->write("HTTP/1.1 310 OK\r\nUuid:{$uuid}"."\r\n\r\n");
                    } else {
                        echo ("send create dynamic connection by ".$tunnelConnection->getRemoteAddress()."\n");
                        $tunnelConnection->write(implode("\r\n", $headers)."\r\n\r\n");
                    }
                   
                    break;
                }
            }
        } else {
            echo "no tunnel connection\r\n";
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
                    'remoteDynamicConnections wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                );
            }
            throw $e;
        });
    }

    public static function handleClientConnection($connection, $request)
    {

        $uri = $request->getHeaderLine('Uri');

        // 是通道
        if (!isset(static::$remoteTunnelConnections[$uri]) || $request->hasHeader('Tunnel')) {
            echo ("add tunnel connection ".$connection->getRemoteAddress()."\n");

            if (!isset(static::$remoteTunnelConnections[$uri])) {
                static::$remoteTunnelConnections[$uri] = new \SplObjectStorage;
            }

            // todo 最大数量限制
            static::$remoteTunnelConnections[$uri]->attach($connection, [
                'Single-Tunnel' => $request->getHeaderLine('Single-Tunnel'),
                'Local-Host' => $request->getHeaderLine('Local-Host'),
                'Uuid' => $request->getHeaderLine('Uuid'),
            ]);

            $ping = function ($connection) {
                $connection->write("HTTP/1.1 300 OK\r\n\r\n");
            };

            $pong = function ($connection) {
                $deferred = new Deferred();

                $connection->once('data', $fn = function ($buffer) use ($deferred) {
                    if (strpos($buffer, 'HTTP/1.1 301 OK') !== false) {
                        $deferred->resolve();
                    }
                });

                \React\Promise\Timer\timeout($deferred->promise(), 3)->then(null, function ($e) use ($connection, $fn, $deferred) {
                    echo ("ping pong timeout\n");
                    $connection->removeListener('data', $fn);
                    if ($e instanceof TimeoutException) {
                        $e =  new \RuntimeException(
                            'wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)',
                            \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                        );
                    }
                    $deferred->reject($e);
                });

                return $deferred->promise();
            };

            $timer = \React\EventLoop\Loop::addPeriodicTimer(10, function () use ($ping, $pong, $connection) {
                echo ("start ping pong".$connection->getRemoteAddress()."\n");
                $ping($connection);
                $pong($connection)->then(function () {
                    echo ("ping pong success\n\n");
                }, function ($e) use ($connection) {
                    echo ("ping pong fail\n\n");
                    $connection->close();
                });
            });

            $connection->on('close', function () use ($uri, $connection, $timer) {
                \React\EventLoop\Loop::cancelTimer($timer);
                static::$remoteTunnelConnections[$uri]->detach($connection);
                if (static::$remoteTunnelConnections[$uri]->count() == 0) {
                    echo ("tunnel connection close\n");
                    unset(static::$remoteTunnelConnections[$uri]);
                    unset(static::$uriToToken[$uri]);
                }
            });


            if ($request->getHeaderLine('Single-Tunnel')) {
                $singleTunnel = new SingleTunnel();
                $singleTunnel->overConnection($connection);
                $singleTunnel->on('connection', function ($singleConnection) use ($connection, $uri) {
                    if (isset(static::$remoteDynamicConnections[$uri]) && static::$remoteDynamicConnections[$uri]->count() > 0) {
                        echo ("add dynamic connection by single tunnel".$singleConnection->getRemoteAddress()."\n");
                        static::$remoteDynamicConnections[$uri]->rewind();
                        $deferred = static::$remoteDynamicConnections[$uri]->current();
                        static::$remoteDynamicConnections[$uri]->detach($deferred);
                        echo ('deferred dynamic connection single-tunnel '.$singleConnection->getRemoteAddress()."\n");
                        $singleConnection->tunnelConnection = $connection;
                        $deferred->resolve($singleConnection);
                    } else {
                        echo ("no dynamic connection by single tunnel".$singleConnection->getRemoteAddress()."\n");
                        $singleConnection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
                        $singleConnection->end();
                    }
                });
            }

            return ;
        }

        echo ("\ndynamic connection connected ".$connection->getRemoteAddress()."\n");

        // todo 最大数量限制
        // 其次请求
        if (isset(static::$remoteDynamicConnections[$uri]) && static::$remoteDynamicConnections[$uri]->count() > 0) {
            echo ("add dynamic connection ".$connection->getRemoteAddress()."\n");

            $localTunnelAddress = $request->getHeaderLine('Uuid');
            $remoteTunnelConnection = null;
            foreach (static::$remoteTunnelConnections[$uri] as $tunnelConnection) {
                if (static::$remoteTunnelConnections[$uri][$tunnelConnection]['Uuid'] == $localTunnelAddress) {
                    $remoteTunnelConnection = $tunnelConnection;
                    break;
                }
            }

            static::$remoteDynamicConnections[$uri]->rewind();
            $deferred = static::$remoteDynamicConnections[$uri]->current();
            static::$remoteDynamicConnections[$uri]->detach($deferred);
            echo ('deferred dynamic connection '.$connection->getRemoteAddress()."\n");
            $connection->tunnelConnection = $remoteTunnelConnection;
            $deferred->resolve($connection);
            return ;
        }
        $connection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
        $connection->end();
    }
}