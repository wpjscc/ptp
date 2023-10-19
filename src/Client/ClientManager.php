<?php

namespace Wpjscc\PTP\Client;

use Ratchet\Http\HttpServer;
use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Config;
use Wpjscc\PTP\Local\LocalManager;
use Wpjscc\PTP\Environment;
use Wpjscc\PTP\P2p\Client\HandleResponse;
use Wpjscc\PTP\P2p\Client\PeerManager;
use Wpjscc\PTP\Server\Http;
use Wpjscc\PTP\Server\TcpManager;
use Wpjscc\PTP\Server\UdpManager;
use Wpjscc\PTP\Server\HttpManager;
use Wpjscc\PTP\Dashboard\DashboardManager;
use Wpjscc\PTP\Tunnel\Client\Tunnel;

class ClientManager implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;
    use \Wpjscc\PTP\Traits\Singleton;

    // 客户端相关
    protected static $tunnelConnections = [];
    protected static $dynamicTunnelConnections = [];


    protected $filterKeys = [];
    protected static $clients = [];
    protected static $p2pTunnels = [];

    protected $configs = [];
    protected $running = false;

    protected $info = [
        'version' => '0.0.1',
        'tunnel_host' => '',
        'tunnel_80_port' => '',
        'tunnel_443_port' => '',
    ];


    public function getInfo()
    {
        return $this->info;
    }

    protected function init()
    {
        $this->configs = Config::instance('client')->getConfigs();
        $this->filterKeys = [
            'tcp',
            'udp',
            'http',
            'common',
            'dashboard',
            'dashboard_client',
        ];
    }

    public function getConfigs()
    {
        return $this->configs;
    }

    public function getFilterKeys()
    {
        return $this->filterKeys;
    }

    // public function getTransformConfigs()
    // {
    //     $configs = [];

    //     $configs['common'] = Config::instance('client')->getClientCommon();

    //     foreach ($this->configs as $key => $config) {
    //         if (!is_array($config)) {
    //             continue;
    //         }
    //         if (in_array($key, $this->filterKeys)) {
    //             continue;
    //         }
    //         $configs[$key] = Config::instance('client')->getClientConfigByKey($key);

    //         if (!isset($config['domain'])) {
    //             unset($configs[$key]['single_tunnel']);
    //             unset($configs[$key]['pool_count']);
    //             unset($configs[$key]['dynamic_tunnel_protocol']);
    //             unset($configs[$key]['local_protocol']);
    //         }
    //     }

    //     // dashboard
    //     $configs['dashboard'] = $this->configs['dashboard'] ?? [];

    //     // http 
    //     $configs['http']['ip'] = HttpManager::instance('client')->getIp();
    //     $configs['http']['ports'] = implode(',',  HttpManager::instance('client')->getPorts());
    //     $configs['tcp']['ip'] = TcpManager::instance('client')->getIp();
    //     $configs['tcp']['ports'] = implode(',', TcpManager::instance('client')->getPorts());
    //     $configs['udp']['ip'] = UdpManager::instance('client')->getIp();
    //     $configs['udp']['ports'] = implode(',', UdpManager::instance('client')->getPorts());

    //     return $configs;
    // }

    public function run()
    {
        if ($this->running) {
            return;
        }
        $this->running = true;

        $this->info['tunnel_host'] = $this->configs['common']['tunnel_host'] ?? '';
        $this->info['tunnel_80_port'] = $this->configs['common']['tunnel_80_port'] ?? '';
        $this->info['tunnel_443_port'] = $this->configs['common']['tunnel_443_port'] ?? '';

        HttpManager::instance('client')->run();
        TcpManager::instance('client')->run();
        UdpManager::instance('client')->run();

        foreach ($this->configs as $key => $config) {
            if (!is_array($config)) {
                continue;
            }
            if (in_array($key, $this->filterKeys)) {
                continue;
            }

            $this->runClient($key);
        }
    }

    public function check()
    {
        if (!$this->running) {
            return;
        }

        $configs = Config::instance('client')->getLatestConfigs();

        HttpManager::instance('client')->check();
        TcpManager::instance('client')->check();
        UdpManager::instance('client')->check();

        $hadKeys = array_keys($this->configs);
        $currentKeys = array_keys($configs);

        $removeKeys = array_diff($hadKeys, $currentKeys);

        foreach ($removeKeys as $key) {
            if (!is_array($this->configs[$key])) {
                continue;
            }
            if (in_array($key, $this->filterKeys)) {
                continue;
            }
            $this->removeClient($key);
        }

        $addKeys = array_diff($currentKeys, $hadKeys);

        foreach ($addKeys as $addKey) {
            if (!is_array($configs[$addKey])) {
                continue;
            }
            if (in_array($addKey, $this->filterKeys)) {
                continue;
            }
            $this->configs[$addKey] = $configs[$addKey];

            $this->runClient($addKey);
        }
    
    }

    public function runClient($key)
    {
        $config = Config::instance('client')->getClientConfigByKey($key);
        $protocol = $config['tunnel_protocol'];
        $number = $config['pool_count'];
        $number = min($number, 5);
        for ($i = 0; $i < $number; $i++) {
            if ($protocol == 'p2p') {
                $this->runP2p($key);
            } else {
                if (isset($config['domain'])) {
                    $client = (new Client($key));
                    static::$clients[$key][] = $client;
                    $client->run();
                    $client->on('remove_' . $key, function () use ($client, $key) {
                        $client->close();
                        unset(static::$clients[$key]);
                    });
                }
            }
        }
        $this->setVisitUriInfo($key);
    }

    public function removeClient($key)
    {
        $protocol = $this->configs[$key]['tunnel_protocol'];

        static::getLogger()->debug("remove client $key", [
            'protocol' => $protocol,
        ]);

        if ($protocol == 'p2p') {
            $this->removeVisitUriInfo($key);

            $tunnels = static::$p2pTunnels[$key] ?? [];
            foreach ($tunnels as $tunnel) {
                $tunnel->emit('remove_' . $key);
            }
        } else {
            $this->removeVisitUriInfo($key);

            $uri = $this->configs[$key]['domain'] ?? '';
            $uris = explode(',', $uri);
            foreach ($uris as $uri) {
                ClientManager::removeTunnelConnectionInKey($key, $uri);
            }
            $clients = static::$clients[$key] ?? [];
            foreach ($clients as $client) {
                $client->emit('remove_' . $key);
            }
        }
       

        unset($this->configs[$key]);

    }

    public function runP2p($key)
    {
        $config = Config::instance('client')->getClientConfigByKey($key);
        $protocol = $config['tunnel_protocol'];
        $tunnel = (new Tunnel($config))->getTunnel($protocol, $key);
        static::$p2pTunnels[$key][] = $tunnel;
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

        $tunnel->on('remove_' . $key, function () use ($tunnel, $key) {
            $tunnel->close();
            unset(static::$p2pTunnels[$key]);
        });
    }
    

    public function setVisitUriInfo($key)
    {
        $config = Config::instance('client')->getClientConfigByKey($key);
        $protocol = $config['tunnel_protocol'] ?? '';
        $tunnelProtocol = $config['dynamic_tunnel_protocol'] ?? '';

        $visitDomain = $config['visit_domain'] ?? '';
        $token = $config['token'] ?? '';
        $visitUris = explode(',', $visitDomain);

        foreach ($visitUris as $visitUri) {
            VisitUriManager::addUriToken($visitUri, $token);
            if (in_array($protocol, ['tls', 'wss']) || in_array($tunnelProtocol, ['tls', 'wss'])) {
                $remoteProxy = 'https://'.$config['tunnel_host'].':'.$config['tunnel_443_port'];
            } else {
                $remoteProxy = 'http://'.$config['tunnel_host'].':'.$config['tunnel_80_port'];
            }
            VisitUriManager::addUriRemoteProxy($visitUri, $remoteProxy);
        }
    }

    public function removeVisitUriInfo($key)
    {

        $config = $this->configs[$key];
        $config = array_merge($config, $this->configs['common']);
        
        $protocol = $config['tunnel_protocol'] ?? '';
        $tunnelProtocol = $config['dynamic_tunnel_protocol'] ?? '';

        $visitDomain = $config['visit_domain'] ?? '';
        $token = $config['token'] ?? '';
        $visitUris = explode(',', $visitDomain);

        foreach ($visitUris as $visitUri) {
            static::getLogger()->debug("remove visit uri $visitUri", [
                'protocol' => $protocol,
                'tunnelProtocol' => $tunnelProtocol,
                'token' => $token,
            ]);
            VisitUriManager::removeUriToken($visitUri, $token);
            if ($config['tunnel_443_port'] ?? ''){
                $remoteProxy = 'https://'.$config['tunnel_host'].':'.$config['tunnel_443_port'];
            } else {
                $remoteProxy = 'http://'.$config['tunnel_host'].':'.$config['tunnel_80_port'];
            }
            // if (in_array($protocol, ['tls', 'wss']) || in_array($tunnelProtocol, ['tls', 'wss'])) {
            //     $remoteProxy = 'https://'.$config['tunnel_host'].':'.$config['tunnel_443_port'];
            // } else {
            //     $remoteProxy = 'http://'.$config['tunnel_host'].':'.$config['tunnel_80_port'];
            // }
            VisitUriManager::removeUriRemoteProxy($visitUri, $remoteProxy);
        }
    }


    public static function addTunnelConnection($uri, $connection, $key)
    {
        $uris = explode(',', $uri);
        foreach ($uris as $uri) {
            if (!isset(ClientManager::$tunnelConnections[$uri])) {
                ClientManager::$tunnelConnections[$uri] = new \SplObjectStorage;
            }
            ClientManager::$tunnelConnections[$uri]->attach($connection, [
                'key' => $key,
            ]);
        }
    }

    public static function removeTunnelConnection($uri, $connection, $key)
    {
        $uris = explode(',', $uri);
        foreach ($uris as $uri) {
            if (isset(ClientManager::$tunnelConnections[$uri])) {
                ClientManager::$tunnelConnections[$uri]->detach($connection);

                if (ClientManager::$tunnelConnections[$uri]->count() == 0) {
                    unset(ClientManager::$tunnelConnections[$uri]);
                }
            }
        }
    }

    public static function removeTunnelConnectionInKey($key, $uri)
    {

        foreach (ClientManager::$tunnelConnections as $hadUri => $connections) {

            if ($hadUri == $uri) {
                foreach ($connections as $connection) {
                    $hadKey = ClientManager::$tunnelConnections[$uri][$connection]['key'];
                    if ($hadKey == $key) {
                        $connection->end();
                    }
                }
            }

        }
    }

    public static function getTunnelUris()
    {
        return array_keys(ClientManager::$tunnelConnections);
    }

    public static function getTunnelConnectionCount($uri)
    {
        if (!isset(ClientManager::$tunnelConnections[$uri])) {
            return 0;
        }
        return ClientManager::$tunnelConnections[$uri]->count();
    }


    public static function  addDynamicTunnelConnection($uri, $connection)
    {
        if (!isset(ClientManager::$dynamicTunnelConnections[$uri])) {
            ClientManager::$dynamicTunnelConnections[$uri] = new \SplObjectStorage;
        }

        ClientManager::$dynamicTunnelConnections[$uri]->attach($connection);
    }

    public static function  removeDynamicTunnelConnection($uri, $connection)
    {
        if (!isset(ClientManager::$dynamicTunnelConnections[$uri])) {
            return;
        }

        ClientManager::$dynamicTunnelConnections[$uri]->detach($connection);

        if (ClientManager::$dynamicTunnelConnections[$uri]->count() == 0) {
            unset(ClientManager::$dynamicTunnelConnections[$uri]);
        }
    }

    public static function getDynamicTunnelUris()
    {
        return array_keys(ClientManager::$dynamicTunnelConnections);
    }

    public static function getDynamicTunnelConnectionCount($uri)
    {
        if (!isset(ClientManager::$dynamicTunnelConnections[$uri])) {
            return 0;
        }
        return ClientManager::$dynamicTunnelConnections[$uri]->count();
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
        return LocalManager::handleLocalConnection($connection, $config, $buffer, $response);
    }
}
