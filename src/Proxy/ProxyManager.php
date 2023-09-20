<?php

namespace Wpjscc\Penetration\Proxy;

use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;

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
                'Server: ReactPHP/1',
                'Uri: '.$uri
            ];
            // 随机发送一个创建链接的请求(给他通道发送)
            $index = rand(0, static::$remoteTunnelConnections[$uri]->count()-1);
            foreach (static::$remoteTunnelConnections[$uri] as $key=>$tunnelConnection) {
                if ($key === $index){
                    $tunnelConnection->write(implode("\r\n", $headers)."\r\n\r\n");
                    break;
                }
            }
        } else {
            echo "no tunnel connection\r\n";
            return \React\Promise\reject(new \Exception('no tunnel connection'));
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
                'Local-Host' => $request->getHeaderLine('Local-Host'),
                'Local-Tunnel-Address' => $request->getHeaderLine('Local-Tunnel-Address'),
            ]);
            $connection->on('close', function () use ($uri, $connection) {
                static::$remoteTunnelConnections[$uri]->detach($connection);
                if (static::$remoteTunnelConnections[$uri]->count() == 0) {
                    echo ("tunnel connection close\n");
                    unset(static::$remoteTunnelConnections[$uri]);
                    unset(static::$uriToToken[$uri]);
                }
            });
            return ;
        }

        echo ("dynamic connection connected\n");

        // todo 最大数量限制
        // 其次请求
        if (isset(static::$remoteDynamicConnections[$uri]) && static::$remoteDynamicConnections[$uri]->count() > 0) {
            echo ("add dynamic connection ".$connection->getRemoteAddress()."\n");

            $localTunnelAddress = $request->getHeaderLine('Local-Tunnel-Address');
            $remoteTunnelConnection = null;
            foreach (static::$remoteTunnelConnections[$uri] as $tunnelConnection) {
                if (static::$remoteTunnelConnections[$uri][$tunnelConnection]['Local-Tunnel-Address'] == $localTunnelAddress) {
                    $remoteTunnelConnection = $tunnelConnection;
                    break;
                }
            }

            static::$remoteDynamicConnections[$uri]->rewind();
            $deferred = static::$remoteDynamicConnections[$uri]->current();
            static::$remoteDynamicConnections[$uri]->detach($deferred);
            echo ('deferred dynamic connection'."\n");
            $connection->tunnelConnection = $remoteTunnelConnection;
            $deferred->resolve($connection);
            return ;
        }
        $connection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
        $connection->end();
    }
}