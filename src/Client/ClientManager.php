<?php

namespace Wpjscc\Penetration\Client;

use Wpjscc\Penetration\Tunnel\Client\Tunnel;
use Wpjscc\Penetration\Tunnel\Client\Tunnel\SingleTunnel;
use React\Socket\Connector;
use RingCentral\Psr7;
use Psr\Log\LoggerInterface;
use Wpjscc\Penetration\Helper;
use Wpjscc\Penetration\Utils\ParseBuffer;
use Wpjscc\Penetration\P2p\Client\HandleResponse;
use Wpjscc\Penetration\P2p\Client\PeerManager;
use Wpjscc\Penetration\Proxy\ProxyManager;
use Ramsey\Uuid\Uuid;
use Wpjscc\Penetration\Utils\PingPong;

class ClientManager implements \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    // 客户端相关
    public static $localTunnelConnections = [];
    public static $localDynamicConnections = [];

    static $configs = [];

    public static function createLocalTunnelConnection($inis)
    {

        $common = $inis['common'];
        $common['timeout']  = $common['timeout'] ?? 6;
        $common['single_tunnel']  = $common['single_tunnel'] ?? 0;
        $common['pool_count']  = $common['pool_count'] ?? 1;
        $common['server_tls']  = $common['server_tls'] ?? false;
        $common['protocol']  = $common['protocol'] ?? '';
        $common['tunnel_protocol']  = $common['tunnel_protocol'] ?? 'tcp';
        unset($inis['common']);


        foreach ($inis as $config) {
            static::$configs = array_merge(static::$configs, [
                array_merge($common, $config)
            ]);
        }

        $function = function ($config) use (&$function) {
            $protocol = $config['protocol'];
            $tunneProtocol = $config['tunnel_protocol'];
            static::getLogger()->debug('start create tunnel connection');

            static::getTunnel($config, $protocol ?: $tunneProtocol)->then(function ($connection) use ($function, &$config) {
                static::getLogger()->debug('Connection established:', [
                    'local_address' => $connection->getLocalAddress(),
                    'remote_address' => $connection->getRemoteAddress(),
                ]);
                $headers = [
                    'GET /client HTTP/1.1',
                    'Host: ' . $config['server_host'],
                    'User-Agent: ReactPHP',
                    'Tunnel: 1',
                    'Authorization: ' . ($config['token'] ?? ''),
                    'Local-Host: ' . $config['local_host'] . ':' . $config['local_port'],
                    'Domain: ' . $config['domain'],
                    'Single-Tunnel: ' . ($config['single_tunnel'] ?? 0),
                    // 'Local-Tunnel-Address: ' . $connection->getLocalAddress(),
                ];

                $request = implode("\r\n", $headers) . "\r\n\r\n";
                static::getLogger()->debug('send create tunnel request', [
                    'request' => $request,
                ]);
                $connection->write($request);

                $buffer = '';
                $connection->on('data', $fn = function ($chunk) use ($connection, &$config, &$buffer, &$fn) {
                    $buffer .= $chunk;
                    ClientManager::handleLocalTunnelBuffer($connection, $buffer, $config, $fn);
                });

                $connection->on('close', function () use ($function, &$config) {
                    static::getLogger()->debug('Connection closed', [
                        'uuid' => $config['uuid'] ?? '',
                    ]);
                    \React\EventLoop\Loop::get()->addTimer(3, function () use ($function, $config) {
                        $function($config);
                    });
                });
            }, function ($e) use ($config, $function) {
                static::getLogger()->error($e->getMessage(), [
                    'class' => __CLASS__,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                \React\EventLoop\Loop::get()->addTimer(3, function () use ($function, $config) {
                    $function($config);
                });
            })->otherwise(function ($e) use ($config, $function) {
                static::getLogger()->error($e->getMessage(), [
                    'class' => __CLASS__,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                \React\EventLoop\Loop::get()->addTimer(3, function () use ($function, $config) {
                    $function($config);
                });
            });
        };


        foreach (static::$configs as $config) {

            if ($config['protocol'] == 'p2p') {
                continue;
            }

            $number = $config['pool_count'];

            for ($i = 0; $i < $number; $i++) {
                $function($config);
            }
        }

        foreach (static::$configs as $config1) {

            if ($config1['protocol'] != 'p2p') {
                continue;
            }

            $number = $config1['pool_count'];

            for ($i = 0; $i < $number; $i++) {
                static::runP2p($config1);
            }
        }
    }

    public static function runP2p($config)
    {
        $protocol = $config['protocol'];
        $tunneProtocol = $config['tunnel_protocol'];
        $tunnel = static::getTunnel($config, $protocol ?: $tunneProtocol);
        $tunnel->on('connection', function ($connection, $response, $address) use (&$config) {
            // 相当于服务端
            $uuid = $config['uuid'];
            // $response = $response->withHeader('Uuid', $uuid);

            // 将对端连接添加到可用连接池
            PeerManager::handleClientConnection($connection, $response, $uuid);

            // 处理对端连接发过来的请求
            $handleResponse = new HandleResponse($connection, $address, $config);
            $handleResponse->on('connection', function ($singleConnection) use ($response, $connection, $uuid) {
                PeerManager::handleOverVirtualConnection($singleConnection, $response, $connection, $uuid);
            });
            // $parseBuffer = new ParseBuffer;
            // $parseBuffer->on('response', [$handleResponse, 'handleResponse']);
            // $connection->on('data', function ($data) use ($parseBuffer) {
            //     $parseBuffer->handleBuffer($data);
            // });
        });
    }

    public static function getTunnel(&$config, $protocol = null)
    {
        return (new Tunnel($config))->getTunnel($protocol);
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
                $response = Psr7\parse_response(substr($buffer, $httpPos, $pos - $httpPos));
            } catch (\Exception $e) {
                // invalid response message, close connection
                static::getLogger()->error($e->getMessage(), [
                    'class' => __CLASS__,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
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
            }
            // 服务端ping
            elseif ($response->getStatusCode() === 300) {
                static::getLogger()->debug('server ping', [
                    'class' => __CLASS__,
                ]);
                $connection->write("HTTP/1.1 301 OK\r\n\r\n");
            }
            elseif ($response->getStatusCode() === 301) {
                static::getLogger()->debug('server pong', [
                    'class' => __CLASS__,
                ]);
            } 
            else {
                static::getLogger()->warning("ignore status_code", [
                    'class' => __CLASS__,
                    'status_code' => $response->getStatusCode(),
                    'reason_phrase' => $response->getReasonPhrase(),
                ]);
                // $connection->close();
                // return;
            }
            ClientManager::handleLocalTunnelBuffer($connection, $buffer, $config);
        }
    }

    public static function addLocalTunnelConnection($connection, $response, &$config)
    {
        $uri = $response->getHeaderLine('Uri');
        $uuid = $response->getHeaderLine('Uuid');

        $config['uri'] = $uri;
        $config['uuid'] = $uuid;

        static::getLogger()->debug('local tunnel success ', [
            'class' => __CLASS__,
            'uri' => $uri,
            'uuid' => $uuid,
            'response' => Helper::toString($response)
        ]);

        if (!isset(static::$localTunnelConnections[$uri])) {
            static::$localTunnelConnections[$uri] = new \SplObjectStorage;
        }

        static::$localTunnelConnections[$uri]->attach($connection);

        $connection->on('close', function () use ($uri, $connection) {
            static::getLogger()->debug('local tunnel connection closed', [
                'class' => __CLASS__,
            ]);
            static::$localTunnelConnections[$uri]->detach($connection);
        });

        // 单通道 接收所有权，处理后续数据请求
        if ($config['single_tunnel'] ?? false) {

            static::getLogger()->debug('current is single tunnel', []);

            $connection->removeAllListeners('data');
            $singleTunnel = (new SingleTunnel());
            $singleTunnel->overConnection($connection);
            $singleTunnel->on('connection', function ($connection, $response) use (&$config) {
                $buffer = '';
                static::handleLocalConnection($connection, $config, $buffer, $response);
            });
        }

        PingPong::pingPong($connection, $connection->getRemoteAddress());
    }
    public static function addLocalDynamicConnection($connection, $response)
    {
        $uri = $response->getHeaderLine('Uri');
        static::getLogger()->info('dynamic tunnel success ', [
            'class' => __CLASS__,
            'uri' => $uri,
            'response' => Helper::toString($response)
        ]);

        if (!isset(static::$localDynamicConnections[$uri])) {
            static::$localDynamicConnections[$uri] = new \SplObjectStorage;
        }

        static::$localDynamicConnections[$uri]->attach($connection);

        $connection->on('close', function () use ($uri, $connection) {
            static::getLogger()->info('local dynamic connection closed', [
                'class' => __CLASS__,
            ]);
            static::$localDynamicConnections[$uri]->detach($connection);
        });
    }

    public static function createLocalDynamicConnections($tunnelConnection, &$config)
    {
        static::getLogger()->notice(__FUNCTION__, [
            'uuid' => $config['uuid'],
        ]);

        static::getTunnel($config)->then(function ($connection) use ($tunnelConnection, $config) {
            $headers = [
                'GET /client HTTP/1.1',
                'Host: ' . $config['server_host'],
                'User-Agent: ReactPHP',
                'Authorization: ' . ($config['token'] ?? ''),
                'Domain: ' . $config['domain'],
                'Uuid: ' . $config['uuid'],
            ];
            $connection->write(implode("\r\n", $headers) . "\r\n\r\n");
            ClientManager::handleLocalDynamicConnection($connection, $config);
        });
    }

    public static function handleLocalDynamicConnection($connection, $config)
    {
        static::getLogger()->notice(__FUNCTION__, [
            'uuid' => $config['uuid'],
        ]);

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
                        static::getLogger()->error($e->getMessage(), [
                            'class' => __CLASS__,
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ]);
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
                    } else {
                        static::getLogger()->error('error', [
                            'status_code' => $response->getStatusCode(),
                            'reason_phrase' => $response->getReasonPhrase(),
                        ]);
                        $connection->removeListener('data', $fn);
                        $fn = null;
                        $connection->close();
                        return;
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
        static::getLogger()->debug(__FUNCTION__, [
            'tunnel_uuid' => $config['uuid'],
            'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
        ]);

        (new \Wpjscc\Penetration\Tunnel\Local\Tunnel($config))->getTunnel($config['local_protocol'] ?? 'tcp')->then(function ($localConnection) use ($connection, &$fn, &$buffer, $config, $response) {

            $connection->removeListener('data', $fn);
            $fn = null;

            static::getLogger()->debug('local connection success', [
                'tunnel_uuid' => $config['uuid'],
                'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
            ]);
            // var_dump($buffer);
            // 交换数据

            $connection->pipe(new \React\Stream\ThroughStream(function ($buffer) use ($config, $connection, $response) {
                if (strpos($buffer, 'POST /close HTTP/1.1') !== false) {
                    static::getLogger()->debug('udp dynamic connection receive close request', [
                        'tunnel_uuid' => $config['uuid'],
                        'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
                        'response' => $buffer
                    ]);
                    $connection->close();
                    return '';
                }

                if ($config['local_replace_host'] ?? false) {
                    $buffer = preg_replace('/^X-Forwarded.*\R?/m', '', $buffer);
                    $buffer = preg_replace('/^X-Real-Ip.*\R?/m', '', $buffer);
                    $buffer = str_replace('Host: ' . $config['uri'], 'Host: ' . $config['local_host'] . ':' . $config['local_port'], $buffer);
                }
                static::getLogger()->debug("dynamic connection receive data ", [
                    'tunnel_uuid' => $config['uuid'],
                    'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
                    'length' => strlen($buffer),
                ]);
                return $buffer;
            }))->pipe($localConnection);

            $localConnection->pipe(new \React\Stream\ThroughStream(function ($buffer) use ($config, $response) {
                static::getLogger()->debug("local connection send data ", [
                    'tunnel_uuid' => $config['uuid'],
                    'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
                    'length' => strlen($buffer),
                ]);
                return $buffer;
            }))->pipe($connection);

            $localConnection->on('end', function () use ($connection, $config, $response) {
                static::getLogger()->debug('local connection end', [
                    'tunnel_uuid' => $config['uuid'],
                    'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
                ]);
                // udp 要发送关闭请求
                if (isset($connection->protocol) && $connection->protocol == 'udp') {
                    static::getLogger()->debug('udp dynamic connection close and try send close requset', [
                        'tunnel_uuid' => $config['uuid'],
                        'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
                        'request' => "POST /close HTTP/1.1\r\n\r\n",
                    ]);
                    $connection->write("POST /close HTTP/1.1\r\n\r\n");
                }
            });

            $localConnection->on('close', function () use ($connection, $config, $response) {
                static::getLogger()->debug('local connection close', [
                    'tunnel_uuid' => $config['uuid'],
                    'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
                ]);
                $connection->close();
            });

            $connection->on('end', function () use ($config, $response) {
                static::getLogger()->debug('dynamic connection end', [
                    'tunnel_uuid' => $config['uuid'],
                    'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
                ]);
            });

            $connection->on('close', function () use ($localConnection, $config, $response) {
                static::getLogger()->debug('dynamic connection close', [
                    'tunnel_uuid' => $config['uuid'],
                    'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
                ]);
                $localConnection->close();
            });

            if ($buffer) {
                if ($config['local_replace_host'] ?? false) {
                    $buffer = preg_replace('/^X-Forwarded.*\R?/m', '', $buffer);
                    $buffer = preg_replace('/^X-Real-Ip.*\R?/m', '', $buffer);
                    $buffer = str_replace('Host: ' . $config['uri'], 'Host: ' . $config['local_host'] . ':' . $config['local_port'], $buffer);
                }
                $localConnection->write($buffer);
                $buffer = '';
            }
        }, function ($e) use ($connection, &$buffer, $config, $response) {
            $buffer = '';
            static::getLogger()->error($e->getMessage(), [
                'class' => __CLASS__,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'tunnel_uuid' => $config['uuid'],
                'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
            ]);
            $content = $e->getMessage();
            $headers = [
                'HTTP/1.0 404 OK',
                'Server: ReactPHP/1',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Length: ' . strlen($content),
            ];
            $header = implode("\r\n", $headers) . "\r\n\r\n";
            $connection->write($header . $content);
        });
    }
}
