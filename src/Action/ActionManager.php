<?php 

namespace Wpjscc\PTP\Action;

use Wpjscc\PTP\Client\ClientManager;
use Wpjscc\PTP\Server\HttpManager;
use Wpjscc\PTP\Server\TcpManager;
use Wpjscc\PTP\Server\UdpManager;
use Wpjscc\PTP\Server\ServerManager;
use Wpjscc\PTP\Proxy\ProxyManager;
use Wpjscc\PTP\Parse\Ini;
use Wpjscc\PTP\Config;


class ActionManager
{
    use \Wpjscc\PTP\Traits\Singleton;

    protected function init()
    {

    }

    public function message()
    {
        if ($this->key == 'client') {
            return $this->client_dashboard_message();
        } else {
            return $this->server_dashboard_message();
        }
    }

    public function client_dashboard_message()
    {
        return [
            'info' => ClientManager::instance($this->key)->getInfo(),
            'http_ports' => [
                'ip' => HttpManager::instance($this->key)->getIp(),
                'ports' => HttpManager::instance($this->key)->getPorts()
            ],
            'tcp_ports' => [
                'ip' => TcpManager::instance($this->key)->getIp(),
                'ports' => TcpManager::instance($this->key)->getPorts()
            ],
            'udp_ports' => [
                'ip' => UdpManager::instance($this->key)->getIp(),
                'ports' => UdpManager::instance($this->key)->getPorts()
            ],
            'proxy_uris' => ClientManager::getTunnelUris(),
            'p2p_proxy_uris' => array_keys(ProxyManager::$remoteTunnelConnections)
        ];
        
    }

    public function server_dashboard_message()
    {
        return [
            'info' => ServerManager::instance($this->key)->getInfo(),
            'http_ports' => [
                'ip' => HttpManager::instance($this->key)->getIp(),
                'ports' => HttpManager::instance($this->key)->getPorts()
            ],
            'tcp_ports' => [
                'ip' => TcpManager::instance($this->key)->getIp(),
                'ports' => TcpManager::instance($this->key)->getPorts()
            ],
            'udp_ports' => [
                'ip' => UdpManager::instance($this->key)->getIp(),
                'ports' => UdpManager::instance($this->key)->getPorts()
            ],
            'proxy_uris' => array_keys(ProxyManager::$remoteTunnelConnections),
        ];
        
    }


    public function getConfigs()
    {
        if ($this->key == 'client') {
            // return ClientManager::instance('client')->getTransformConfigs();
            return Config::instance('client')->getLatestConfigs();
        } else {
            return Config::instance('server')->getLatestConfigs();
        }
    }


    public function addClientConfig($key, $config)
    {
        $configs = $this->getConfigs();

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
        $configs = $this->getConfigs();
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

    public function addHttpPort($port, $ip = '127.0.0.1')
    {
        $configs = $this->getConfigs();

        $ports = HttpManager::instance($this->key)->getPorts();
        if (in_array($port, $ports)) {
            throw new \Exception("port $port already exists");
        }
        
        $ports[] = $port;

        $configs['http']['ip'] = $ip;
        $configs['http']['ports'] = implode(',', $ports);
        $iniString = (new Ini)->render($configs);

        Config::instance($this->key)->overrideConfig($iniString);
    }

    public function removeHttpPort($port)
    {
        $configs = $this->getConfigs();

        $ports = HttpManager::instance($this->key)->getPorts();
        if (!in_array($port, $ports)) {
            throw new \Exception("port $port not exists");
        }

        $index = array_search($port, $ports);
        unset($ports[$index]);

        $configs['http']['ports'] = implode(',', $ports);
        $iniString = (new Ini)->render($configs);

        Config::instance($this->key)->overrideConfig($iniString);
    }



    public function addTcpPort($port, $ip = '127.0.0.1')
    {
        $configs = $this->getConfigs();

        $ports = TcpManager::instance($this->key)->getPorts();
        if (in_array($port, $ports)) {
            throw new \Exception("port $port already exists");
        }
        
        $ports[] = $port;

        $configs['tcp']['ip'] = $ip;
        $configs['tcp']['ports'] = implode(',', $ports);
        $iniString = (new Ini)->render($configs);

        Config::instance($this->key)->overrideConfig($iniString);
    }

    public function removeTcpPort($port)
    {
        $configs = $this->getConfigs();

        $ports = TcpManager::instance($this->key)->getPorts();
        if (!in_array($port, $ports)) {
            throw new \Exception("port $port not exists");
        }

        $index = array_search($port, $ports);
        unset($ports[$index]);

        $configs['tcp']['ports'] = implode(',', $ports);
        var_dump($configs);
        $iniString = (new Ini)->render($configs);

        Config::instance($this->key)->overrideConfig($iniString);
    }

    public function addUdpPort($port, $ip = '127.0.0.1')
    {
        $configs = $this->getConfigs();

        $ports = UdpManager::instance($this->key)->getPorts();
        if (in_array($port, $ports)) {
            throw new \Exception("port $port already exists");
        }
        $ports[] = $port;
        $configs['udp']['ip'] = $ip;
        $configs['udp']['ports'] = implode(',', $ports);
        $iniString = (new Ini)->render($configs);
        Config::instance($this->key)->overrideConfig($iniString);
    }


    public function removeUdpPort($port)
    {
        $configs = $this->getConfigs();

        $ports = UdpManager::instance($this->key)->getPorts();
        if (!in_array($port, $ports)) {
            throw new \Exception("port $port not exists");
        }

        $index = array_search($port, $ports);
        unset($ports[$index]);

        $configs['udp']['ports'] = implode(',', $ports);
        $iniString = (new Ini)->render($configs);

        Config::instance($this->key)->overrideConfig($iniString);
    }
    



}