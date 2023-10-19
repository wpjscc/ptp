<?php 

namespace Wpjscc\PTP\Action;

use Wpjscc\PTP\Client\ClientManager;
use Wpjscc\PTP\Server\HttpManager;
use Wpjscc\PTP\Server\TcpManager;
use Wpjscc\PTP\Server\UdpManager;
use Wpjscc\PTP\Proxy\ProxyManager;
use Wpjscc\PTP\Parse\Ini;
use Wpjscc\PTP\Config;


class ActionManager
{
    use \Wpjscc\PTP\Traits\Singleton;

    protected function init()
    {

    }

    public function client_dashboard_message()
    {
        return [
            'info' => ClientManager::instance('client')->getInfo(),
            'http_ports' => [
                'ip' => HttpManager::instance('client')->getIp(),
                'ports' => HttpManager::instance('client')->getPorts()
            ],
            'tcp_ports' => [
                'ip' => TcpManager::instance('client')->getIp(),
                'ports' => TcpManager::instance('client')->getPorts()
            ],
            'udp_ports' => [
                'ip' => UdpManager::instance('client')->getIp(),
                'ports' => UdpManager::instance('client')->getPorts()
            ],
            'proxy_uris' => ClientManager::getTunnelUris(),
            'p2p_proxy_uris' => array_keys(ProxyManager::$remoteTunnelConnections)
        ];
        
    }


    public function getClientConfigs()
    {
        return ClientManager::instance('client')->getTransformConfigs();
    }


    public function addClientConfig($key, $config)
    {
        $configs = $this->getClientConfigs();

        if (isset($configs[$key])) {
            throw new \Exception("config $key already exists");
        }

        if (!isset($config['domain']) && !isset($config['visit_domain'])) {
            throw new \Exception("domain or visit_domain is required");
        }

        if (isset($config['domain'])) {
            if (!isset($config['local_host'], $config['local_port'])) {
                throw new \Exception("local_host and local_port is required");
            }
        }

        if (isset($config['visit_domain'])) {
            if (!isset($config['token'])) {
                // 当visit_domain 存在时，token 必须存在
                throw new \Exception("token is required");
            }
        }

        $configs[$key] = $config;

        $iniString = (new Ini)->render($configs);

        Config::instance('client')->overrideConfig($iniString);
        
    }

    public function deleteClientConfig($key)
    {
        $configs = $this->getClientConfigs();
        if (!isset($configs[$key])) {
            throw new \Exception("config $key not exists");
        }

        if (in_array($key, ClientManager::instance('client')->getFilterKeys())) {
            throw new \Exception("config $key is not allowed to delete");
        }

        unset($configs[$key]);

        $iniString = (new Ini)->render($configs);

        Config::instance('client')->overrideConfig($iniString);
    }

    public function addClientTcpPort($port, $ip = '127.0.0.1')
    {
        $configs = $this->getClientConfigs();

        $ports = TcpManager::instance('client')->getPorts();
        if (in_array($port, $ports)) {
            throw new \Exception("port $port already exists");
        }

        
        $ports[] = $port;

        $configs['tcp']['ip'] = $ip;
        $configs['tcp']['ports'] = implode(',', $ports);
        $iniString = (new Ini)->render($configs);

        Config::instance('client')->overrideConfig($iniString);
    }

    public function removeClientTcpPort($port)
    {
        $configs = $this->getClientConfigs();

        $ports = TcpManager::instance('client')->getPorts();
        if (!in_array($port, $ports)) {
            throw new \Exception("port $port not exists");
        }

        $index = array_search($port, $ports);
        unset($ports[$index]);

        $configs['tcp']['ports'] = implode(',', $ports);
        $iniString = (new Ini)->render($configs);

        Config::instance('client')->overrideConfig($iniString);
    }

    public function addClientUdpPort($port, $ip = '127.0.0.1')
    {
        $configs = $this->getClientConfigs();

        $ports = UdpManager::instance('client')->getPorts();
        if (in_array($port, $ports)) {
            throw new \Exception("port $port already exists");
        }
        $ports[] = $port;
        $configs['udp']['ip'] = $ip;
        $configs['udp']['ports'] = implode(',', $ports);
        $iniString = (new Ini)->render($configs);
        Config::instance('client')->overrideConfig($iniString);

    }

    public function removeClientUdpPort($port)
    {
        $configs = $this->getClientConfigs();

        $ports = UdpManager::instance('client')->getPorts();
        if (!in_array($port, $ports)) {
            throw new \Exception("port $port not exists");
        }

        $index = array_search($port, $ports);
        unset($ports[$index]);

        $configs['udp']['ports'] = implode(',', $ports);
        $iniString = (new Ini)->render($configs);

        Config::instance('client')->overrideConfig($iniString);
    }



}