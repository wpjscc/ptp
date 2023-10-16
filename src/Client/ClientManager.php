<?php

namespace Wpjscc\PTP\Client;


use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Config;
use Wpjscc\PTP\Local\LocalManager;
use Wpjscc\PTP\Environment;
use Wpjscc\PTP\P2p\Client\HandleResponse;
use Wpjscc\PTP\P2p\Client\PeerManager;
use Wpjscc\PTP\Server\Http;
use Wpjscc\PTP\Server\TcpManager;
use Wpjscc\PTP\Server\UdpManager;
use Wpjscc\PTP\Tunnel\Client\Tunnel;

class ClientManager implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    // 客户端相关
    protected static $tunnelConnections = [];
    protected static $dynamicTunnelConnections = [];


    protected static $clients = [];
    protected static $p2pTunnels = [];


    protected $configs = [];
    protected $running = false;

    public function __construct()
    {
        $this->configs = Config::getKey('');
    }


    public function run()
    {
        if ($this->running) {
            return;
        }
        $this->running = true;

        $this->runCommon();
        $this->runTcp();
        $this->runUdp();

        foreach ($this->configs as $key => $config) {
            if (!is_array($config)) {
                continue;
            }
            if (in_array($key, ['common'])) {
                continue;
            }

            if (in_array($key, ['tcp', 'udp'])) {
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


        Environment::getTcpManager() && Environment::getTcpManager()->check();
        Environment::getUdpManager() && Environment::getUdpManager()->check();

        $hadKeys = array_keys($this->configs);

        $configs = Config::getKey('');

        $currentKeys = array_keys($configs);


        $removeKeys = array_diff($hadKeys, $currentKeys);

        foreach ($removeKeys as $key) {
            if (!is_array($this->configs[$key])) {
                continue;
            }
            if (in_array($key, ['common'])) {
                continue;
            }

            if (in_array($key, ['tcp', 'udp'])) {
                continue;
            }
            $this->removeClient($key);
        }

        $addKeys = array_diff($currentKeys, $hadKeys);

        foreach ($addKeys as $addKey) {
            if (!is_array($configs[$addKey])) {
                continue;
            }
            if (in_array($addKey, ['common'])) {
                continue;
            }

            if (in_array($addKey, ['tcp', 'udp'])) {
                continue;
            }
            $this->configs[$addKey] = $configs[$addKey];

            $this->runClient($addKey);
        }
    
    }

    protected function runCommon()
    {
        $common = Config::getClientCommon();

        $localServer80Port = $common['local_server_80_port'] ?? '';

        if ($localServer80Port) {
            $httpServer = new Http($localServer80Port);
            $httpServer->run();
            Environment::addHttpServer($httpServer);
        }
    }

    protected function runTcp()
    {
        $tcpManager = TcpManager::create(
            Config::getTcpIp($this->configs) ?: '127.0.0.1',
            Config::getTcpPorts($this->configs)
        );
        $tcpManager->run();
        Environment::addTcpManager($tcpManager);
    }

    protected function runUdp()
    {
        $udpManager = UdpManager::create(
            Config::getUdpIp($this->configs) ?: '127.0.0.1',
            Config::getUdpPorts($this->configs)
        );
        $udpManager->run();
        Environment::addUdpManager($udpManager);
    }

    public function runClient($key)
    {
        $config = Config::getClientConfigByKey($key);
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
                    $client->on('remove_' . $key, function () use ($client) {
                        $client->close();
                    });
                }
            }
        }
        $this->setVisitUriInfo($key);
    }

    public function removeClient($key)
    {
        $protocol = $this->configs[$key]['tunnel_protocol'];
        if ($protocol == 'p2p') {
            $tunnels = static::$p2pTunnels[$key] ?? [];
            foreach ($tunnels as $tunnel) {
                $tunnel->emit('remove_' . $key);
            }
            unset(static::$p2pTunnels[$key]);
        } else {
            $uri = $this->configs[$key]['domain'] ?? '';
            $uris = explode(',', $uri);
            foreach ($uris as $uri) {
                ClientManager::removeTunnelConnectionInKey($key, $uri);
            }
            $clients = static::$clients[$key] ?? [];
            foreach ($clients as $client) {
                $client->emit('remove_' . $key);
            }
            unset(static::$clients[$key]);

            $this->removeVisitUriInfo($key);
        }
       

        unset($this->configs[$key]);

    }


    public function runP2p($key)
    {
        $config = Config::getClientConfigByKey($key);
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

        $tunnel->on('remove_' . $key, function () use ($tunnel) {
            $tunnel->close();
        });
    }
    

    public function setVisitUriInfo($key)
    {
        $config = Config::getClientConfigByKey($key);
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
        $protocol = $config['tunnel_protocol'] ?? '';
        $tunnelProtocol = $config['dynamic_tunnel_protocol'] ?? '';

        $visitDomain = $config['visit_domain'] ?? '';
        $token = $config['token'] ?? '';
        $visitUris = explode(',', $visitDomain);

        foreach ($visitUris as $visitUri) {
            VisitUriManager::removeUriToken($visitUri, $token);
            if (in_array($protocol, ['tls', 'wss']) || in_array($tunnelProtocol, ['tls', 'wss'])) {
                $remoteProxy = 'https://'.$config['tunnel_host'].':'.$config['tunnel_443_port'];
            } else {
                $remoteProxy = 'http://'.$config['tunnel_host'].':'.$config['tunnel_80_port'];
            }
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
