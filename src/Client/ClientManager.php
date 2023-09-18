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
            'timeout' => 6,
            // 本地的地址
            // local ssl
            'local_tls' => false,
            // domain or ip
            'local_host' => '127.0.0.1',
            'local_port' => '8088',
            // local_proxy->local_host
            // see https://github.com/clue/reactphp-http-proxy#proxyconnector
            'local_proxy' => '',
            // 当local_host 指向的域名没有控制权时，将 request header 中的HOST 替换成 local_host 并把 xff 头去掉，这对于代理第三方api 很有用
            'local_replace_host' => false,
    
            // 服务器的地址
            'remote_host' => 'reactphp-intranet-penetration.xiaofuwu.wpjs.cc',
            'remote_port' => '32123',
            'remote_tls' => false,
            
            // 暂时没用-为了自定义域名使用，该域名需指向服务器ip
            'remote_domain' => 'reactphp-intranet-penetration.xiaofuwu.wpjs.cc',

            'token' => 'xxxxxx',
        ]
    ];

    public static function createLocalTunnelConnection(
        $localHost,
        $localPort,
        $domain,
        $token,
        $remoteHost = null,
        $remotePort = null,
        $remoteTls = false,
        $localTls = false,
        $localproxy = null,
        $localReplaceHost = false
        )
    {
        foreach (static::$configs as $config) {
            $config['remote_tls'] = $remoteTls;
            $config['local_tls'] = $localTls;
            $config['local_host'] = $localHost;
            $config['local_port'] = $localPort;
            $config['remote_domain'] = $domain;
            $config['token'] = $token;

            if ($localproxy) {
                $config['local_proxy'] = $localproxy;
            }

            if ($localReplaceHost) {
                $config['local_replace_host'] = $localReplaceHost;
            }

            if ($remoteHost) {
                $config['remote_host'] = $remoteHost;
            }
            
            if ($remotePort) {
                $config['remote_port'] = $remotePort;
            }



            $function = function ($config) use (&$function) {

                (new Connector(array('timeout' => $config['timeout'])))->connect(($config['remote_tls'] ? 'tls' : 'tcp') .  "://".$config['remote_host'].":".$config['remote_port'])->then(function ($connection) use ($function, &$config) {
                    echo 'Connection established to: ' . $connection->getRemoteAddress() . "\n";
                    $headers = [
                        'GET /client HTTP/1.1',
                        'Host: '.$config['remote_host'],
                        'User-Agent: ReactPHP',
                        'Tunnel: 1',
                        'Authorization: '. $config['token'],
                        'Local-Host: '.$config['local_host'].':'.$config['local_port'],
                        'Remote-Domain: '.$config['remote_domain'],
                        'Local-Tunnel-Address: '.$connection->getLocalAddress(),
                    ];
                    $connection->write(implode("\r\n", $headers)."\r\n\r\n");
                    
                    $buffer = '';
                    $connection->on('data', $fn = function ($chunk) use ($connection, &$config, &$buffer, &$fn) {
                        $buffer .= $chunk;
                        ClientManager::handleLocalTunnelBuffer($connection, $buffer, $config, $fn);
                    });

                    $connection->on('close', function () use ($function, $config) {
                        echo 'Connection closed'."\n";
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

    public static function handleLocalTunnelBuffer($connection, &$buffer, &$config)
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
                static::addLocalTunnelConnection($connection, $response, $config);
            } 
            // 请求创建代理连接
            elseif ($response->getStatusCode() === 201) {
                static::createLocalDynamicConnections($connection, $config);
            } else {
                echo $response->getStatusCode();
                echo $response->getReasonPhrase();
                $connection->close();
                return ;
            }
            ClientManager::handleLocalTunnelBuffer($connection, $buffer, $config);
        }
    }

    public static function addLocalTunnelConnection($connection, $response, &$config)
    {
        $uri = $response->getHeaderLine('Uri');
        echo ('local tunnel success '.$uri."\n");
        $config['uri'] = $uri;
        echo ($connection->getRemoteAddress().'=>'. $connection->getLocalAddress())."\n";

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
        echo ('local proxy success '.$uri."\n");
        echo ($connection->getRemoteAddress().'=>'. $connection->getLocalAddress())."\n";

        if (!isset(static::$localDynamicConnections[$uri])) {
            static::$localDynamicConnections[$uri] = new \SplObjectStorage;
        }

        static::$localDynamicConnections[$uri]->attach($connection);

        $connection->on('close', function () use ($uri, $connection) {
            echo 'local dynamic connection closed'."\n";
            static::$localDynamicConnections[$uri]->detach($connection);
        });
       
    }

    public static function createLocalDynamicConnections($tunnelConnection, &$config)
    {
        (new Connector(array('timeout' => $config['timeout'])))->connect("tcp://".$config['remote_host'].":".$config['remote_port'])->then(function ($connection) use ($tunnelConnection, $config) {
            $headers = [
                'GET /client HTTP/1.1',
                'Host: '.$config['remote_host'],
                'User-Agent: ReactPHP',
                'Authorization: '. $config['token'],
                'Remote-Domain: '.$config['remote_domain'],
                'Local-Tunnel-Address: '.$tunnelConnection->getLocalAddress(),
            ];
            $connection->write(implode("\r\n", $headers)."\r\n\r\n");
            ClientManager::handleLocalDynamicConnection($connection, $config);
        });
    }

    public static function handleLocalDynamicConnection($connection, $config)
    {
        echo '开始监听请求...'."\n";
        echo $connection->getRemoteAddress()."\n";
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
        $proxy = null;

        if ($config['local_proxy']) {
            $proxy = new \Clue\React\HttpProxy\ProxyConnector($config['local_proxy']);
        }

        (new Connector(array_merge(array(
            'timeout' => $config['timeout'],
        ), ($proxy ? [
            'tcp' => $proxy,
            'dns' => false,
        ] : []))))->connect(($config['local_tls'] ? 'tls' : 'tcp') .  "://".$config['local_host'].":".$config['local_port'])->then(function ($localConnection) use ($connection, &$fn, &$buffer, $config) {

            $connection->removeListener('data', $fn);
            $fn = null;

            echo 'local connection success'."\n";
            // var_dump($buffer);
            // 交换数据
            $connection->pipe(new \React\Stream\ThroughStream(function($buffer) use ($config) {
                if ($config['local_replace_host']) {
                    $buffer = preg_replace('/^X-Forwarded.*\R?/m', '', $buffer);
                    $buffer = preg_replace('/^X-Real-Ip.*\R?/m', '', $buffer);
                    $buffer = str_replace('Host: ' .$config['uri'], 'Host: '.$config['local_host'].':'.$config['local_port'], $buffer);
                }
                return $buffer;
            }))->pipe($localConnection);
            $localConnection->pipe($connection);
            $localConnection->on('end', function(){
                echo 'local connection end'."\n";
            });
            if ($buffer) {
                if ($config['local_replace_host']) {
                    $buffer = preg_replace('/^X-Forwarded.*\R?/m', '', $buffer);
                    $buffer = preg_replace('/^X-Real-Ip.*\R?/m', '', $buffer);
                    $buffer = str_replace('Host: ' .$config['uri'], 'Host: '.$config['local_host'].':'.$config['local_port'], $buffer);
                }
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
            static::$remoteTunnelConnections[$uri]->attach($connection, [
                'Local-host' => $request->getHeaderLine('Local-Host'),
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
            $localTunnelAddress = $request->getHeaderLine('Local-Tunnel-Address');
            $remoteTunnelConnection = null;
            foreach (static::$remoteTunnelConnections[$uri] as $tunnelConnection) {
                var_dump(static::$remoteTunnelConnections[$uri][$tunnelConnection]['Local-Tunnel-Address']);
                if (static::$remoteTunnelConnections[$uri][$tunnelConnection]['Local-Tunnel-Address'] == $localTunnelAddress) {
                    $remoteTunnelConnection = $tunnelConnection;
                    break;
                }
            }
            var_dump($localTunnelAddress);
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