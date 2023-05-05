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
            'local_port' => '8088',
    
            // 链接的地址
            'remote_host' => 'reactphp-intranet-penetration.xiaofuwu.wpjs.cc',
            'remote_port' => '8081',

            'token' => 'xxxxxx'
        ]
    ];

    public static function createLocalTunnelConnection()
    {
        foreach (static::$configs as $config) {
            $function = function($config) use(&$function) {
                (new Connector(array('timeout' => $config['timeout'])))->connect("tcp://".$config['remote_host'].":".$config['remote_port'])->then(function ($connection) use ($function, $config) {
                    $headers = [
                        'GET /client HTTP/1.1',
                        'Host: reactphp-intranet-penetration.xiaofuwu.wpjs.cc',
                        'User-Agent: ReactPHP',
                        'Tunnel: 1',
                        'Authorization: '. $config['token'],
                        'Local-Host: '.$config['local_host'].':'.$config['local_port'],
                    ];
                    $connection->write(implode("\r\n", $headers)."\r\n\r\n");
                    
                    $buffer = '';
                    $connection->on('data', $fn = function ($chunk) use ($connection, $config, &$buffer, &$fn) {
                        $buffer .= $chunk;
                        ClientManager::handleLocalTunnelBuffer($connection, $buffer, $config, $fn);
                    });

                    $connection->on('close', function () use ($function, $config) {
                        \React\EventLoop\Loop::get()->addTimer(3, function() use ($function, $config){
                            $function($config);
                        });
                    });
                }, function ($e) use ($config, $function) {
                    echo 'Connection failed: ' . $e->getMessage() . PHP_EOL;
                    \React\EventLoop\Loop::get()->addTimer(3, function() use ($function, $config){
                        $function($config);
                    });

                });
            };

            $function($config);
            
        }
    }

    public static function handleLocalTunnelBuffer($connection, &$buffer, $config)
    {
        $pos = strpos($buffer, "\r\n\r\n");
        if ($pos !== false) {
            $httpPos = strpos($buffer, "HTTP/1.1");
            if ($httpPos === false) {
                $httpPos = 0;
            }
            try {
                $response = Psr7\parse_response(substr($buffer, $httpPos, $pos-$httpPos));
            } catch (\Exception $e) {
                // invalid response message, close connection
                echo $e->getMessage();
                $connection->close();
                return;
            }
            $buffer = substr($buffer, $pos + 4);
            // 创建通道成功
            if ($response->getStatusCode() === 200) {
                static::addLocalTunnelConnection($connection, $response);
            } 
            // 请求创建代理连接
            elseif ($response->getStatusCode() === 201) {
                static::createLocalDynamicConnections($config);
            } else {
                echo $response->getStatusCode();
                echo $response->getReasonPhrase();
                $connection->close();
                return ;
            }
            ClientManager::handleLocalTunnelBuffer($connection, $buffer, $config);
        }
    }

    public static function addLocalTunnelConnection($connection, $response)
    {
        $uri = $response->getHeaderLine('Uri');
        echo ('local tunnel success '.$uri."\n");
        var_dump($connection->getLocalAddress());
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
            echo 'local dynamic connection closed'."\n";
            static::$localDynamicConnections[$uri]->detach($connection);
        });
       
    }

    public static function createLocalDynamicConnections($config)
    {
        (new Connector(array('timeout' => $config['timeout'])))->connect("tcp://".$config['remote_host'].":".$config['remote_port'])->then(function ($connection) use ($config) {
            $headers = [
                'GET /client HTTP/1.1',
                'Host: reactphp-intranet-penetration.xiaofuwu.wpjs.cc',
                'User-Agent: ReactPHP',
                'Authorization: '. $config['token']
            ];
            $connection->write(implode("\r\n", $headers)."\r\n\r\n");
            ClientManager::handleLocalDynamicConnection($connection, $config);
        });
    }

    public static function handleLocalDynamicConnection($connection, $config)
    {
        echo '开始监听请求...'."\n";
        echo $connection->getLocalAddress()."\n";
        $buffer = '';
        $connection->on('data', $fn = function ($chunk) use ($connection, $config, &$buffer, &$fn) {
            $buffer .= $chunk;
            while ($buffer) {
                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos !== false) {
                    $httpPos = strpos($buffer, "HTTP/1.1");
                    if ($httpPos === false) {
                        $httpPos = 0;
                    }
                    try {
                        $response = Psr7\parse_response(substr($buffer, $httpPos, $pos));
                    } catch (\Exception $e) {
                        // invalid response message, close connection
                        echo $e->getMessage();
                        $connection->close();
                        return;
                    }

                    $buffer = substr($buffer, $pos + 4);

                    // 第一次创建代理成功
                    if ($response->getStatusCode() === 200) {
                        ClientManager::addLocalDynamicConnection($connection, $response);
                    // 第二次过来请求了
                    } elseif ($response->getStatusCode() === 201) {
                        $connection->removeListener('data', $fn);
                        $fn = null;
                        ClientManager::handleLocalConnection($connection, $config, $buffer, $response);
                        break;
                    }else {
                        echo 'error'."\n";
                        $connection->removeListener('data', $fn);
                        $fn = null;
                        echo $response->getStatusCode();
                        echo $response->getReasonPhrase();
                        $connection->close();
                        return ;
                    }
                } else {
                    break;
                }
            }

        });
        $connection->resume();

    }

    public static function handleLocalConnection($connection, $config, &$buffer, $response)
    {
        $connection->on('data', $fn = function ($chunk) use (&$buffer) {
            $buffer .= $chunk;
        });
        echo ('start handleLocalConnection'."\n");

        (new Connector(array('timeout' => $config['timeout'])))->connect("tcp://".$config['local_host'].":".$config['local_port'])->then(function ($localConnection) use ($connection, $config, &$fn, &$buffer, $response) {
            var_dump($connection->getRemoteAddress());

            $connection->removeListener('data', $fn);
            $fn = null;

            echo 'local connection success'."\n";

            // 交换数据
            $connection->pipe($localConnection);
            $localConnection->pipe($connection);

            $connection->resume();
            $localConnection->resume();

            if ($buffer) {
                $localConnection->write($buffer);
                $buffer = '';
            }

        }, function($e) use ($connection) {
            $content = $e->getMessage();
            $headers = [
                'HTTP/1.0 404 OK',
                'Server: ReactPHP/1',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Length: '.strlen($content),
            ];
            $connection->write(implode("\r\n", $headers)."\r\n\r\n".$content);
        });
    }




    // 以下服务端相关

    public static $remoteTunnelConnections = [];

    public static $remoteDynamicConnections = [];


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
            // 发送一个创建链接的请求(给他通道发送)
            static::$remoteTunnelConnections[$uri]->current()->write(implode("\r\n", $headers)."\r\n\r\n");
        } else {
            return \React\Promise\reject('no tunnel connection');
        }

        if (!isset(static::$remoteDynamicConnections[$uri])) {
            static::$remoteDynamicConnections[$uri] = new \SplObjectStorage;
        }

        static::$remoteDynamicConnections[$uri]->attach($deferred);

        return \React\Promise\Timer\timeout($deferred->promise(), 3)->then(null, function ($e) use ($uri, $deferred) {
            
            ClientManager::$remoteDynamicConnections[$uri]->detach($deferred);

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
        echo ("add connection\n");
        var_dump($uri, $request->hasHeader('Tunnel'), !isset(static::$remoteTunnelConnections[$uri]));

        // 是通道
        if (!isset(static::$remoteTunnelConnections[$uri]) || $request->hasHeader('Tunnel')) {
            if (!isset(static::$remoteTunnelConnections[$uri])) {
                static::$remoteTunnelConnections[$uri] = new \SplObjectStorage;
            }

            // todo 最大数量限制
            static::$remoteTunnelConnections[$uri]->attach($connection, $request->getHeaderLine('Local-Host'));
            $connection->on('close', function () use ($uri, $connection) {
                static::$remoteTunnelConnections[$uri]->detach($connection);
                if (static::$remoteTunnelConnections[$uri]->count() == 0) {
                    echo ("tunnel connection close\n");
                    unset(static::$remoteTunnelConnections[$uri]);
                }
            });
            return ;
        }

        echo ("dynamic connection connected\n");

        // todo 最大数量限制
        // 其次请求
        if (isset(static::$remoteDynamicConnections[$uri]) && static::$remoteDynamicConnections[$uri]->count() > 0) {
            $deferred = static::$remoteDynamicConnections[$uri]->current();
            static::$remoteDynamicConnections[$uri]->detach($deferred);
            echo ('deferred dynamic connection'."\n");
            $deferred->resolve($connection);
            return ;
        }
        $connection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
        $connection->end();
    }
}