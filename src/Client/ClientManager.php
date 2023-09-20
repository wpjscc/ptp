<?php

namespace Wpjscc\Penetration\Client;

use Wpjscc\Penetration\Tunnel\Client\Tunnel;
use React\Socket\Connector;
use RingCentral\Psr7;

class ClientManager
{

    // 客户端相关
    public static $localTunnelConnections = [];
    public static $localDynamicConnections = [];

    static $configs = [
    ];

    public static function createLocalTunnelConnection($inis)
    {
        
        $common = $inis['common'];
        $common['timeout']  = $common['timeout'] ?? 6;
        $common['server_tls']  = $common['server_tls'] ?? false;
        $common['tunnel_protocol']  = $common['tunnel_protocol'] ?? 'tcp';
        unset($inis['common']);


        foreach ($inis as $config) {
            static::$configs = array_merge(static::$configs, [
                array_merge($common, $config)
            ]);
        }


        foreach (static::$configs as $config) {

            $function = function ($config) use (&$function) {

                static::getTunnel($config)->then(function ($connection) use ($function, &$config) {
                    echo 'Connection established : ' . $connection->getLocalAddress() . " ====> " . $connection->getRemoteAddress() . "\n";
                    $headers = [
                        'GET /client HTTP/1.1',
                        'Host: ' . $config['server_host'],
                        'User-Agent: ReactPHP',
                        'Tunnel: 1',
                        'Authorization: ' . ($config['token'] ?? ''),
                        'Local-Host: ' . $config['local_host'] . ':' . $config['local_port'],
                        'Domain: ' . $config['domain'],
                        'Local-Tunnel-Address: ' . $connection->getLocalAddress(),
                    ];
                    $connection->write(implode("\r\n", $headers) . "\r\n\r\n");

                    $buffer = '';
                    $connection->on('data', $fn = function ($chunk) use ($connection, &$config, &$buffer, &$fn) {
                        $buffer .= $chunk;
                        ClientManager::handleLocalTunnelBuffer($connection, $buffer, $config, $fn);
                    });

                    $connection->on('close', function () use ($function, $config) {
                        echo 'Connection closed' . "\n";
                        \React\EventLoop\Loop::get()->addTimer(3, function () use ($function, $config) {
                            $function($config);
                        });
                    });
                }, function ($e) use ($config, $function) {
                    echo 'Connection failed: ' . $e->getMessage() . PHP_EOL;
                    \React\EventLoop\Loop::get()->addTimer(3, function () use ($function, $config) {
                        $function($config);
                    });

                })->otherwise(function ($e) use ($config, $function) {
                    echo 'Connection failed-1: ' . $e->getMessage() . PHP_EOL;
                    \React\EventLoop\Loop::get()->addTimer(3, function () use ($function, $config) {
                        $function($config);
                    });
                });
 
            };

            $function($config);
            
        }
    }

    public static function getTunnel($config)
    {
        return (new Tunnel($config))->getTunnel();
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
        echo ($connection->getLocalAddress().'=====>'. $connection->getRemoteAddress())."\n";

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
        static::getTunnel($config)->then(function ($connection) use ($tunnelConnection, $config) {
            $headers = [
                'GET /client HTTP/1.1',
                'Host: '.$config['server_host'],
                'User-Agent: ReactPHP',
                'Authorization: '. ($config['token'] ?? ''),
                'Domain: '.$config['domain'],
                'Dynamic-Tunnel-Address: '.$connection->getLocalAddress(),
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

        if ($config['local_proxy'] ?? '') {
            $proxy = new \Clue\React\HttpProxy\ProxyConnector($config['local_proxy']);
        }

        (new Connector(array_merge(array(
            'timeout' => $config['timeout'],
        ), ($proxy ? [
            'tcp' => $proxy,
            'dns' => false,
        ] : []))))->connect((($config['local_tls'] ?? false) ? 'tls' : 'tcp') .  "://".$config['local_host'].":".$config['local_port'])->then(function ($localConnection) use ($connection, &$fn, &$buffer, $config) {

            $connection->removeListener('data', $fn);
            $fn = null;

            echo 'local connection success'."\n";
            // var_dump($buffer);
            // 交换数据
            $connection->pipe(new \React\Stream\ThroughStream(function($buffer) use ($config) {
                if ($config['local_replace_host'] ?? false) {
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
                if ($config['local_replace_host'] ?? false) {
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

    
}