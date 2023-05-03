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

    public static $localConnections = [];

    static $configs = [
        [
            'timeout' => 3,
            // 本地的地址
            'local_host' => '127.0.0.1',
            'local_port' => '8088',
    
            // 链接的地址
            'remote_host' => '127.0.0.1',
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

    public static function handleLocalTunnelBuffer($connection, &$buffer, $config, $fn = null)
    {
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
            $buffer = substr($buffer, $pos + 4);
            // 创建通道成功
            if ($response->getStatusCode() === 200) {
                static::addLocalTunnelConnection($connection, $response);
            } 
            // 请求创建代理连接
            elseif ($response->getStatusCode() === 201) {
                static::createLocalDynamicConnections($config);
            } 
            // 代理连接用户端关闭
            elseif ($response->getStatusCode() === 204) {
                static::removeLocalConnection($connection, $response);
                return ;
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

        $connection->tunnelConnection = static::$localTunnelConnections[$uri]->current();

        static::$localDynamicConnections[$uri]->attach($connection);

        $connection->on('close', function () use ($uri, $connection) {
            echo 'local dynamic connection closed-1111'."\n";
            static::$localDynamicConnections[$uri]->detach($connection);
        });
       
    }

    public static function createLocalDynamicConnections($config)
    {
        (new Connector(array('timeout' => $config['timeout'])))->connect("tcp://".$config['remote_host'].":".$config['remote_port'])->then(function ($connection) use ($config) {
            $headers = [
                'GET / HTTP/1.1',
                'User-Agent: ReactPHP',
                'Authorization: '. $config['token'],
            ];
            $connection->write(implode("\r\n", $headers)."\r\n\r\n");
            $connection->on('close', function () use ($connection) {
                echo 'local dynamic connection closed'."\n";
            });

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
                    try {
                        $response = Psr7\parse_response(substr($buffer, 0, $pos));
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
                        ClientManager::handleLocalConnection($connection, $config, $buffer);
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

    public static function handleLocalConnection($connection, $config, &$buffer)
    {
        $connection->on('data', $fn = function ($chunk) use (&$buffer) {
            $buffer .= $chunk;
        });
        echo ('start handleLocalConnection'."\n");

        (new Connector(array('timeout' => $config['timeout'])))->connect("tcp://".$config['local_host'].":".$config['local_port'])->then(function ($localConnection) use ($connection, $config, &$fn, &$buffer) {
            static::$localConnections[$connection->getLocalAddress()] = $localConnection;
            $connection->removeListener('data', $fn);
            $fn = null;
            echo 'local connection success'."\n";
            $connection->pipe($localConnection);
            $localConnection->pipe($connection, ['end' => false]);
            $localConnection->on('close', function () use ($connection, $config) {
                // localConnection 是主动关闭的，告诉远程
                if (isset(static::$localConnections[$connection->getLocalAddress()])) {
                    echo 'local connection end'."\n";
                    unset(static::$localConnections[$connection->getLocalAddress()]);
                    $headers = [
                        'POST / HTTP/1.1',
                        'User-Agent: ReactPHP',
                        'Authorization: '. $config['token'],
                        'Remote-Uniqid: '.$connection->getLocalAddress(),
                    ];
                    var_dump('tunnelConnection',$connection->tunnelConnection->getLocalAddress());
                    $connection->tunnelConnection->write(implode("\r\n", $headers)."\r\n\r\n");
                } else {
                    echo 'local connection end-111'."\n";
                }
                // 继续监听后面的连接
                ClientManager::handleLocalDynamicConnection($connection, $config);
            });
            
            
            if ($buffer) {
                $localConnection->write($buffer);
                $buffer = '';
            }

        });
    }

    public function removeLocalConnection($connection, $response)
    {
        $uniqid = $response->getHeaderLine('Remote-Uniqid');
        if (isset(static::$localConnections[$uniqid])) {
            echo 'remove local connection'."\n";
            $localConnection = static::$localConnections[$uniqid];
            unset(static::$localConnections[$uniqid]);
            $localConnection->end();
        }
        
    }











    // 以下服务端相关

    public static $remoteTunnelConnections = [];

    public static $remoteDynamicConnections = [];


    public static function createRemoteDynamicConnection($uri)
    {

        $deferred = new Deferred();
        
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
        $tunnelConnection = static::$remoteTunnelConnections[$uri]->current();
        var_dump('tunnelConnection' ,$tunnelConnection->getRemoteAddress());
        static::$remoteDynamicConnections[$uri]->attach($deferred, $tunnelConnection);

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

    public static function addClientConnection($connection, $request, &$buffer)
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
            static::$remoteTunnelConnections[$uri]->attach($connection);
            $connection->on('close', function () use ($uri, $connection) {
                static::$remoteTunnelConnections[$uri]->detach($connection);
                if (static::$remoteTunnelConnections[$uri]->count() == 0) {
                    echo ("tunnel connection close\n");
                    unset(static::$remoteTunnelConnections[$uri]);
                }
            });

            static::handleTunnelIncomingBuffer($connection, $buffer);
            

            // 通道连接监听
            $connection->on('data', function ($chunk) use (&$buffer, $connection) {
                $buffer .= $chunk;
                static::handleTunnelIncomingBuffer($connection, $buffer);
            });


            // 初始化一个 dynamic connection
            // if (!isset(static::$remoteDynamicConnections[$uri]) || static::$remoteDynamicConnections[$uri]->count() == 0) {
            //     echo ("start create dynamic connection\n");

            //     static::createRemoteDynamicConnection($uri)->then(function ($connection) use ($uri) {
            //         echo ("dynamic connection create success\n");
            //         ProxyManager::getProxyConnection($uri)->addIdleConnection($connection);
            //     }, function ($e) use ($uri) {
            //         echo ("dynamic connection create failed\n");
            //         echo ($e->getMessage());
            //         $deferred = static::$remoteDynamicConnections[$uri]->current();
            //         static::$remoteDynamicConnections[$uri]->detach($deferred);
            //     });
            // }
            return ;
        }

        echo ("dynamic connection connected\n");

        // todo 最大数量限制
        // 其次请求
        if (isset(static::$remoteDynamicConnections[$uri]) && static::$remoteDynamicConnections[$uri]->count() > 0) {
            $deferred = static::$remoteDynamicConnections[$uri]->current();
            $tunnelConnection = static::$remoteDynamicConnections[$uri][$deferred];
            static::$remoteDynamicConnections[$uri]->detach($deferred);
            echo ('deferred dynamic connection'."\n");
            $connection->tunnelConnection = $tunnelConnection;
            $deferred->resolve($connection);
            return ;
        }

        $connection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
        $connection->end();
        return ;

        // 不支持主动创建，服务端发起创建

        // 给$connection设置一个tunnelConnection
        foreach (static::$remoteTunnelConnections[$uri] as $tunnelConnection) {
            if ($tunnelConnection->getRemoteAddress() === $connection->getRemoteAddress){
                echo ('dynamic connection'."\n");
                $connection->tunnelConnection = $tunnelConnection;
                break;
            }
        }

        if (empty($connection->tunnelConnection)) {
            echo ('no tunnel connection'."\n");
            $connection->write("HTTP/1.1 404 Not Found tunnel connection\r\n\r\n");
            $connection->end();
            return ;
        }

        $headers = [
            'HTTP/1.1 201 OK',
            'Server: ReactPHP/1',
            'Dynamic: true',
            'Uri: '.$uri
        ];
        $connection->write(implode("\r\n", $headers)."\r\n\r\n");

        // 最后空闲
        ProxyManager::getProxyConnection($uri)->addIdleConnection($connection);
    }


    public static function handleTunnelIncomingBuffer($connection, &$buffer)
    {
        // 避免buffer 没有使用干净
        while ($buffer) {
            $pos = strpos($buffer, "\r\n\r\n");
            if ($pos !== false) {
                try {
                    $request = Psr7\parse_request(substr($buffer, 0, $pos));
                } catch (\Exception $e) {
                    // invalid request message, close connection
                    $buffer = '';
                    $connection->write($e->getMessage());
                    $connection->close();
                    return;
                }
                // 只有一种情况 local 主动关闭
                if ($request->getMethod() == "POST") {
                    if (isset(ProxyManager::$userConnections[$request->getHeaderLine('Remote-Uniqid')])) {
                        $userConnection = ProxyManager::$userConnections[$request->getHeaderLine('Remote-Uniqid')];
                        unset(ProxyManager::$userConnections[$request->getHeaderLine('Remote-Uniqid')]);
                        $userConnection->end();
                    }
                }

                $buffer = (string) substr($buffer, $pos + 4);
            } else {
                break;
            }
        }
    }
}