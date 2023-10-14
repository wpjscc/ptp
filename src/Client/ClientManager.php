<?php

namespace Wpjscc\PTP\Client;

use Wpjscc\PTP\Tunnel\Client\Tunnel;
use Wpjscc\PTP\Tunnel\Client\Tunnel\SingleTunnel;
use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Utils\ParseBuffer;
use Wpjscc\PTP\P2p\Client\HandleResponse;
use Wpjscc\PTP\P2p\Client\PeerManager;
use Wpjscc\PTP\Utils\PingPong;

class ClientManager implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    // 客户端相关
    public static $localTunnelConnections = [];
    public static $localDynamicConnections = [];

    static $common = [];
    static $configs = [];
    // static $uriToInfo = [];
    static $visitUriToInfo = [];

    public static function createLocalTunnelConnection($inis)
    {

        $common = $inis['common'];
        $common['timeout']  = $common['timeout'] ?? 6;
        $common['single_tunnel']  = $common['single_tunnel'] ?? 0;
        $common['pool_count']  = $common['pool_count'] ?? 1;
        $common['tunnel_protocol']  = $common['tunnel_protocol'] ?? 'tcp';
        $common['dynamic_tunnel_protocol']  = $common['dynamic_tunnel_protocol'] ?? 'tcp';
        if (empty($common['local_protocol'])) {
            $common['local_protocol'] = 'tcp';
            // 当本地服务服务时 http 时 ，需要主动打开
            //$common['local_replace_host'] = '1';
        }
        static::$common = $common;
        unset($inis['common']);

        foreach ($inis as $config) {
            static::$configs = array_merge(static::$configs, [
                array_merge($common, $config)
            ]);
        }

        // 运行tunnel
        foreach (static::$configs as $config) {
            if ($config['tunnel_protocol'] == 'p2p') {
                continue;
            }
            $number = $config['pool_count'];

            for ($i = 0; $i < $number; $i++) {
                // 设置点对点访问的信息 (流量经过服务端) is_private 并且 token 一样
                //                           Server
                //                         
                //                                |
                //                                |
                //         +-------tcp/tls--------+--------tcp/tls-------+
                //         |                                             |
                //         |                                             |
                //         |                                             |
                //      Client A                                      Client B
                // Client A 和 Client B 能通过本地域名互相访问
                static::setVisitUriInfo($config);
                // 注册通道
                //                           Server
                //                         
                //                                |------<----tcp/udp/tls/wss/ws/http/https----<----+
                //                                |                                                 |
                //         +-tcp/tls/udp/wss/ws-<-+->-tcp/tls/udp/wss/ws-+                          |   
                //         |                                             |                          User 
                //         |                                             |
                //         |                                             |
                //      Client A                                      Client B
                // 外部User 可通过 tunnel 访问到 Client A 或者 Client B 的内部服务
                if (!isset($config['domain'])) {
                    continue;
                }
                static::runTunnel($config);
            }
        }


        // 运行p2p,打通后,流量不经过服务端
        //                              Server
        //                         
        //                                |
        //                                |
        //         +----------------------+----------------------+
        //         |                                             |
        //      ----------------------------------------------------------
        //         |                                             |
        //      Client A <--------------tcp/udp--------------> Client B
        // 打通后可通过tcp 或者 udp 访问对端
        foreach (static::$configs as $config1) {
            if ($config1['tunnel_protocol'] != 'p2p') {
                continue;
            }
            $number = $config1['pool_count'];
            for ($i = 0; $i < $number; $i++) {
                // 可以不需要设置点对点访问的信息（仅仅是为了适配 visit_domain）
                static::setVisitUriInfo($config1);

                static::runP2p($config1);
            }
        }
    }

    public static function runTunnel($config)
    {
        $function = function ($config) use (&$function) {
            $protocol = $config['tunnel_protocol'];
            $tunneProtocol = $config['dynamic_tunnel_protocol'];
            static::getLogger()->debug('start create tunnel connection');

            static::getTunnel($config, $protocol)->then(function ($connection) use ($function, &$config, $protocol) {
                static::getLogger()->debug('Connection established:', [
                    'local_address' => $connection->getLocalAddress(),
                    'remote_address' => $connection->getRemoteAddress(),
                ]);
                $request = implode("\r\n", [
                    'GET /client HTTP/1.1',
                    'Host: ' . $config['tunnel_host'],
                    'User-Agent: ReactPHP',
                    'X-Is-Ptp: 1',
                    'Tunnel: 1',
                    'Secret-Key: '. ($config['secret_key'] ?? ''),
                    'Authorization: ' . ($config['token'] ?? ''),
                    'Local-Host: ' . $config['local_host'] . (($config['local_port']??'') ? (':'. $config['local_port']) : ''),
                    'Local-Protocol: ' . $config['local_protocol'],
                    'Local-Replace-Host: ' . ($config['local_replace_host'] ?? 0),
                    'Domain: ' . $config['domain'],
                    'Single-Tunnel: ' . ($config['single_tunnel'] ?? 0),
                    'Is-Private: ' . ($config['is_private'] ?? 0),
                    'Http-User: '. ($config['http_user'] ?? ''),
                    'Http-Pwd: '. ($config['http_pwd'] ?? ''),
                    "\r\n"
                ]);
                static::getLogger()->debug('send create tunnel request', [
                    'request' => $request,
                    'protocol' => $protocol
                ]);
                $connection->write($request);

                $parseBuffer = new ParseBuffer();
                $parseBuffer->on('response', function ($response) use ($connection, &$config) {
                    static::handleTunnelResponse($response, $connection, $config);
                });

                $connection->on('data', function ($chunk) use ($parseBuffer) {
                    $parseBuffer->handleBuffer($chunk);
                });

                $connection->on('close', function () use ($function, $config) {
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

        $function($config);
    }


    public static function setVisitUriInfo($config, $isDelete  = false)
    {
        $visitDomain = $config['visit_domain'] ?? '';
        $token = $config['token'] ?? '';
        $visitUris = explode(',', $visitDomain);
        foreach ($visitUris as $key => $visitUri) {
            static::$visitUriToInfo[$visitUri]['config'] = $config;
            if (!isset(static::$visitUriToInfo[$visitUri]['tokens'])) {
                static::$visitUriToInfo[$visitUri]['tokens'] = [];
            }
            if ($token) {
                static::$visitUriToInfo[$visitUri]['tokens'][] = $token;
            }
            static::$visitUriToInfo[$visitUri]['tokens'] = array_unique(static::$visitUriToInfo[$visitUri]['tokens']);

            $protocol = static::$common['tunnel_protocol'] ?? '';
            $tunnelProtocol = static::$common['dynamic_tunnel_protocol'] ?? '';

            if (in_array($protocol, ['tls', 'wss']) || in_array($tunnelProtocol, ['tls', 'wss'])) {
                static::$visitUriToInfo[$visitUri]['remote_proxy'] = 'https://'.$config['tunnel_host'].':'.$config['tunnel_443_port'];
            } else {
                static::$visitUriToInfo[$visitUri]['remote_proxy'] = 'http://'.$config['tunnel_host'].':'.$config['tunnel_80_port'];
            }

        }

    }

    public static function runP2p($config)
    {
        $protocol = $config['tunnel_protocol'];
        $tunneProtocol = $config['dynamic_tunnel_protocol'];
        $tunnel = static::getTunnel($config, $protocol ?: $tunneProtocol);
        $tunnel->on('connection', function ($connection, $response, $address) use (&$config) {
            // 相当于服务端
            $uuid = $config['uuid'];

            // 将对端连接添加到可用连接池
            PeerManager::handleClientConnection($connection, $response, $uuid);

            // 处理对端连接发过来的请求
            $handleResponse = new HandleResponse($connection, $address, $config);
            $handleResponse->on('connection', function ($singleConnection) use ($response, $connection, $uuid) {
                PeerManager::handleOverVirtualConnection($singleConnection, $response, $connection, $uuid);
            });
        });
    }

    public static function getTunnel(&$config, $protocol = null)
    {
        return (new Tunnel($config))->getTunnel($protocol);
    }

    // 处理通道返回的数据
    //                           Server x.x.x.x
    //                         
    //                                |
    //                                |
    //                                ↓
    //         +-----<----Tunnel---<---
    //         |                                             
    //         |                                             
    //         ↓                                             
    //      Client                                                                       
    public static function handleTunnelResponse($response, $connection, &$config)
    {
        // 服务端返回成功
        if ($response->getStatusCode() === 200) {
            static::addLocalTunnelConnection($connection, $response, $config);
        }
        // 请求创建代理连接
        elseif ($response->getStatusCode() === 201) {
            static::createDynamicTunnelConnections($connection, $config);
        }
        // 服务端ping
        elseif ($response->getStatusCode() === 300) {
            static::getLogger()->debug('server ping', [
                'class' => __CLASS__,
            ]);
            // $connection->write("HTTP/1.1 301 OK\r\n\r\n");
        }
        // 服务端pong
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
        }
    }

    // 添加通道
    protected static function addLocalTunnelConnection($connection, $response, &$config)
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
            if (static::$localTunnelConnections[$uri]->count() == 0) {
               unset(static::$localTunnelConnections[$uri]);
            }
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


    // 创建动态代理连接
    //                              Server
    //         +--dynamic tunnel-----  +  ----<<<<<<<------+
    //         |                                             |                                           
    //      Client                                          User 
    public static function createDynamicTunnelConnections($tunnelConnection, &$config)
    {
        static::getLogger()->notice(__FUNCTION__, [
            'uuid' => $config['uuid'],
        ]);
        $tunneProtocol = $config['dynamic_tunnel_protocol'];

        static::getTunnel($config, $tunneProtocol)->then(function ($connection) use ($tunnelConnection, $config) {
            $connection->write(implode("\r\n", [
                'GET /client HTTP/1.1',
                'Host: ' . $config['tunnel_host'],
                'X-Is-Ptp: 1',
                'User-Agent: ReactPHP',
                'Authorization: ' . ($config['token'] ?? ''),
                'Domain: ' . $config['domain'],
                'Uuid: ' . $config['uuid'],
                "\r\n"
            ]));
            static::handleDynamicTunnelConnection($connection, $config);
        });
    }

    public static function handleDynamicTunnelConnection($connection, $config)
    {
        static::getLogger()->notice(__FUNCTION__, [
            'uuid' => $config['uuid'],
        ]);
        $parseBuffer = new ParseBuffer();
        $parseBuffer->on('response', function ($response, $parseBuffer) use ($connection, $config) {
            static::handleDynamicTunnelResponse($response, $connection, $config, $parseBuffer);
        });
        $connection->on('data', [$parseBuffer, 'handleBuffer']);
        $connection->resume();
    }

    public static function handleDynamicTunnelResponse($response, $connection, $config, $parseBuffer)
    {
        // 第一次创建代理成功
        if ($response->getStatusCode() === 200) {
            ClientManager::addLocalDynamicConnection($connection, $response);
            // 第二次过来请求了
        } elseif ($response->getStatusCode() === 201) {
            $connection->removeAllListeners('data');
            $buffer = $parseBuffer->pullBuffer();
            ClientManager::handleLocalConnection($connection, $config, $buffer, $response);
        } else {
            static::getLogger()->error('error', [
                'status_code' => $response->getStatusCode(),
                'reason_phrase' => $response->getReasonPhrase(),
            ]);
            $connection->close();
        }
    }


    // 处理本地请求
    //                             local proxy                                  Server
    //                                |                                            |
    //                                |                                            |-----------tcp/tls/wss/ws/http/https-----CU-----+
    //                                UC                                           |                                                |
    //                                |                                            |                                                |
    //         +---------CU--CB-------+-------CU--CB---------+--------CU--CB-------|--tcp/tls/udp/wss/ws----CB--+                   |   
    //         |                      |                      |                                                  |                   |
    //         |                      |                      |                                                  |                   |
    //         |                      |                      |                                                  |                   User
    //         |                      |                      |                                                  |
    //         |                      |                      |                                                  |
    //       localhost C--tcp/tls/wss/http/https/unix---CA--Client A ---------------tcp/udp-----AB--------------Client B
    // 到达 localhost 的路径有三条

    // User --> Server --> Client A --> local (内网穿透)
    // Client B --> Server --> Client A --> local （点对点 with server）
    // Client B --> Client A --> local （打孔后 点对点 no server）

    // User->Server 的协议可以是 tcp/tls/wss/ws/http/https
    // Client->Server 的协议可以是 tcp/tls/udp/wss/ws
    // Client->local 的协议可以是 tcp/tls/wss/http/https/unix

    public static function handleLocalConnection($connection, $config, &$buffer, $response)
    {
        $connection->on('data', $fn = function ($chunk) use (&$buffer) {
            $buffer .= $chunk;
        });
        static::getLogger()->debug(__FUNCTION__, [
            'tunnel_uuid' => $config['uuid'],
            'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
        ]);
        $localProcol = $config['local_protocol'] ?? 'tcp';
        (new \Wpjscc\PTP\Tunnel\Local\Tunnel($config))->getTunnel($localProcol)->then(function ($localConnection) use ($connection, &$fn, &$buffer, $config, $response, $localProcol) {

            $connection->removeListener('data', $fn);
            $fn = null;

            static::getLogger()->debug('local connection success', [
                'tunnel_uuid' => $config['uuid'],
                'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
            ]);
            // var_dump($buffer);
            // 交换数据
            $isReplaced = false;
            $connection->pipe(new \React\Stream\ThroughStream(function ($buffer) use ($config, $connection, $response, $localProcol, &$isReplaced) {
                if (strpos($buffer, 'POST /close HTTP/1.1') !== false) {
                    static::getLogger()->debug('udp dynamic connection receive close request', [
                        'tunnel_uuid' => $config['uuid'],
                        'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
                        'response' => $buffer
                    ]);
                    $connection->close();
                    return '';
                }
                
                if ($config['local_remove_xff'] ?? false) {
                    $buffer = preg_replace('/^X-Forwarded.*\R?/m', '', $buffer);
                    $buffer = preg_replace('/^X-Real-Ip.*\R?/m', '', $buffer);
                }

                if ($config['local_replace_host'] ?? false) {
                    // $buffer = preg_replace('/^X-Forwarded.*\R?/m', '', $buffer);
                    // $buffer = preg_replace('/^X-Real-Ip.*\R?/m', '', $buffer);
                    $buffer = str_replace('Host: ' . $config['uri'], 'Host: ' . $config['local_host'] . ':' . $config['local_port'], $buffer);
                }

                static::getLogger()->debug("dynamic connection receive data ", [
                    'tunnel_uuid' => $config['uuid'],
                    'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
                    'length' => strlen($buffer),
                ]);
                if ($localProcol == 'unix' && !$isReplaced) {
                    $isReplaced = true;
                    $domain = explode(',', $config['domain'])[0];
                    $buffer = str_replace('Host: ' . $config['local_host'], 'Host: '.$domain, $buffer);
                }
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
                if ($config['local_remove_xff'] ?? false) {
                    $buffer = preg_replace('/^X-Forwarded.*\R?/m', '', $buffer);
                    $buffer = preg_replace('/^X-Real-Ip.*\R?/m', '', $buffer);
                }
                if ($config['local_replace_host'] ?? false) {
                    // $buffer = preg_replace('/^X-Forwarded.*\R?/m', '', $buffer);
                    // $buffer = preg_replace('/^X-Real-Ip.*\R?/m', '', $buffer);
                    $buffer = str_replace('Host: ' . $config['uri'], 'Host: ' . $config['local_host'] . ':' . $config['local_port'], $buffer);
                }
                if ($localProcol == 'unix') {
                    $domain = explode(',', $config['domain'])[0];
                    $buffer = str_replace('Host: ' . $config['local_host'], 'Host: '.$domain, $buffer);
                }

                $localConnection->write($buffer);
                $buffer = '';
            }
        }, function ($e) use ($connection, &$buffer, $config, $response) {
            static::getLogger()->error($e->getMessage(), [
                'class' => __CLASS__,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'tunnel_uuid' => $config['uuid'],
                'buffer' => $buffer,
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
            $buffer = '';
        });
    }
}
