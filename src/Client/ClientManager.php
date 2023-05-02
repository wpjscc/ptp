<?php

namespace Wpjscc\Penetration\Client;

use Wpjscc\Penetration\Proxy\ProxyManager;
use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use React\Socket\Connector;
use RingCentral\Psr7;

class ClientManager
{



    // 客户端相关
    public static $localTunnelConnections = [];
    public static $localDynamicConnections = [];

    static $configs = [
        [
            'timeout' => 3,
            // 本地的地址
            'local_host' => '127.0.0.1',
            'local_port' => '80',
    
            // 链接的地址
            'remote_host' => '127.0.0.1',
            'remote_port' => '8081',

            'token' => 'xxxxxx'
        ]
    ];

    public static function createLocalTunnelConnection()
    {
        foreach (static::$configs as $config) {
            (new Connector(array('timeout' => $config['timeout'])))->connect("tcp://".$config['remote_host'].":".$config['remote_port'])->then(function ($connection) use ($config) {
                $headers = [
                    'GET / HTTP/1.1',
                    'Host: 127.0.0.1:8080',
                    'User-Agent: ReactPHP',
                    'Tunnel: 1',
                    'Authorization: '. $config['token'],
                ];
                $connection->write(implode("\r\n", $headers)."\r\n\r\n");
                
                $buffer = '';
                $connection->on('data', $fn = function ($chunk) use ($connection, $config, &$buffer, &$fn) {
                    $buffer .= $chunk;

                    $pos = strpos($buffer, "\r\n\r\n");
                    if ($pos !== false) {
                        try {
                            $response = Psr7\parse_response(substr($buffer, 0, $pos));
                        } catch (\Exception $e) {
                            // invalid response message, close connection
                            echo $e->getMessage();
                            $connection->close();
                            return;
                        }
                        $buffer = substr($buffer, $pos + 2);
                        if ($response->getStatusCode() === 200) {
                            static::addLocalTunnelConnection($connection, $response);
                        } elseif ($response->getStatusCode() === 201) {
                            static::createLocalDynamicConnections($response, $config);
                        } else {
                            echo $response->getStatusCode();
                            echo $response->getReasonPhrase();
                            $connection->close();
                            return ;
                        }

                    }
                });
            });
        }
    }

    public static function addLocalTunnelConnection($connection, $response)
    {
        $uri = $response->getHeaderLine('Uri');

        if (!isset(static::$localTunnelConnections[$uri])) {
            static::$localTunnelConnections[$uri] = new \SplObjectStorage;
        }

        static::$localTunnelConnections[$uri]->attach($connection);

        $connection->on('close', function () use ($uri, $connection) {
            static::$localTunnelConnections[$uri]->detach($connection);
        });
       
    }
    public static function addLocalDynamicConnection($connection, $response)
    {
        $uri = $response->getHeaderLine('Uri');

        if (!isset(static::$localDynamicConnections[$uri])) {
            static::$localDynamicConnections[$uri] = new \SplObjectStorage;
        }

        static::$localDynamicConnections[$uri]->attach($connection);

        $connection->on('close', function () use ($uri, $connection) {
            static::$localDynamicConnections[$uri]->detach($connection);
        });
       
    }

    public static function createLocalDynamicConnections($config)
    {
        (new Connector(array('timeout' => $config['timeout'])))->connect("tcp://".$config['remote_host'].":".$config['remote_port'])->then(function ($connection) use ($config) {
            $headers = [
                'GET / HTTP/1.1',
                'Host: 127.0.0.1:8080',
                'User-Agent: ReactPHP',
                'Authorization: '. $config['token'],
            ];
            $connection->write(implode("\r\n", $headers)."\r\n\r\n");
            static::handleLocalDynamicConnection($connection, $config);
        });
    }

    public static function handleLocalDynamicConnection($connection, $config)
    {
        $buffer = '';
        $connection->on('data', $fn = function ($chunk) use ($connection, $config, &$buffer, &$fn) {
            $buffer .= $chunk;

            $pos = strpos($buffer, "\r\n\r\n");
            if ($pos !== false) {
                try {
                    $response = Psr7\parse_response(substr($buffer, 0, $pos));
                } catch (\Exception $e) {
                    // invalid response message, close connection
                    echo $e->getMessage();
                    $connection->close();
                    return;
                }

                $buffer = substr($buffer, $pos + 2);

                if ($response->getStatusCode() === 200) {
                    static::addLocalDynamicConnection($connection, $response);
                } elseif ($response->getStatusCode() === 201) {
                    static::handleLocalConnection($connection, $config);
                    $connection->removeListener('data', $fn);
                    $fn = null;
                }else {
                    $connection->removeListener('data', $fn);
                    $fn = null;
                    echo $response->getStatusCode();
                    echo $response->getReasonPhrase();
                    $connection->close();
                    return ;
                }
            }
        });
    }

    public static function handleLocalConnection($connection, $config)
    {
        (new Connector(array('timeout' => $config['timeout'])))->connect("tcp://".$config['local_host'].":".$config['local_port'])->then(function ($localConnection) use ($connection, $config) {
            $connection->pipe($localConnection);
            $localConnection->pipe($connection, ['end' => false]);
            $localConnection->on('close', function () use ($connection, $config) {
                static::handleLocalDynamicConnection($connection, $config);
            });
        });
    }





    // 以下服务端相关

    public static $remoteTunnelConnections = [];

    public static $remoteDynamicConnections = [];


    public static function createConnection($uri)
    {

        $deferred = new Deferred();
        
        if (isset(static::$remoteTunnelConnections[$uri]) && static::$remoteTunnelConnections[$uri]->count() > 0) {
            $headers = [
                'HTTP/1.1 201 OK',
                'Server: ReactPHP/1',
                'Uri: '.$uri
            ];
            // 发送一个创建链接的请求
            static::$remoteTunnelConnections[$uri]->current()->write(implode("\r\n", $headers)."\r\n\r\n");
        } else {
            return \React\Promise\reject('no proxy connection');
        }

        if (!isset(static::$remoteDynamicConnections[$uri])) {
            static::$remoteDynamicConnections[$uri] = new \SplObjectStorage;
        }
        static::$remoteDynamicConnections[$uri]->attach($deferred);

        $that = static;

        return \React\Promise\Timer\timeout($deferred->promise(), 3)->then(null, function ($e) use ($that, $deferred) {
            
            $that::$remoteDynamicConnections->detach($deferred);

            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'remoteDynamicConnections wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                );
            }
            throw $e;
        });
    }

    public static function addClientConnection($connection, $request)
    {
        $host = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();
        $uri = $host;
        if ($port) {
            $uri = $uri.':'.$port;
        }

        // 是通道
        if (!isset(static::$remoteTunnelConnections[$uri]) || $request->hasHeader('Tunnel')) {
            if (!isset(static::$remoteTunnelConnections[$uri])) {
                static::$remoteTunnelConnections[$uri] = new \SplObjectStorage;
            }

            // todo 最大数量限制
            static::$remoteTunnelConnections[$uri]->attach($connection);
            $connection->on('close', function () use ($uri, $connection) {
                static::$remoteTunnelConnections[$uri]->detach($connection);
                if (static::$remoteTunnelConnections[$uri]->count() == 0) {
                    unset(static::$remoteTunnelConnections[$uri]);
                }
            });
            return ;
        }

        // todo 最大数量限制
        // 其次请求
        if (isset(static::$remoteDynamicConnections[$uri])) {
            $deferred = static::$remoteDynamicConnections[$uri]->current();
            static::$remoteDynamicConnections[$uri]->detach($deferred);
            $deferred->resolve($connection);
            return ;
        }

        // 最后空闲
        ProxyManager::getProxyConnection($uri)->addIdleConnection($connection);
    }
}