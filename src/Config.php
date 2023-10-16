<?php

namespace Wpjscc\PTP;

use Wpjscc\PTP\Parse\Ini;


class Config
{
    use \Wpjscc\PTP\Traits\Singleton;
    
    protected $configs;
    protected static $inis;

    protected function init()
    {
        $this->refresh();
    }

    public function getConfigs()
    {
        return $this->configs;
    }
    public function getLatestConfigs()
    {
        $this->refresh();
        return $this->configs;
    }

    protected function refresh()
    {
        if ($this->key == 'client') {
            $iniPath = getParam('--ini-path', './ptpc.ini');
        } else {
            $iniPath = getParam('--ini-path', './ptps.ini');
        }

        if (strpos($iniPath, '/') !== 0) {
            if (strpos($iniPath, './') === 0) {
                $iniPath = ltrim($iniPath, './');
            }
            $iniPath = getcwd() . '/' . $iniPath;
        }
        var_dump($iniPath);

        if (!$iniPath || !file_exists($iniPath)) {
            throw new \Exception('--iniPath is required');
        }
        
        $this->configs = (new Ini)->parse(file_get_contents($iniPath));
    }

    public function getValue($key, $default = null)
    {
        $keys = explode('.', $key);

        $value = $this->configs;
        
        foreach ($keys as $key) {
            $value = $value[$key] ?? null;
        }

        if ($value === null) {
            return $default;
        }

        return $value;
    }


    public function getClientCommon()
    {
        $common = $this->getValue('common');
        $common['timeout']  = $common['timeout'] ?? 6;
        $common['single_tunnel']  = $common['single_tunnel'] ?? 0;
        $common['pool_count']  = $common['pool_count'] ?? 1;
        $common['tunnel_protocol']  = $common['tunnel_protocol'] ?? 'tcp';
        $common['dynamic_tunnel_protocol']  = $common['dynamic_tunnel_protocol'] ?? 'tcp';
        $common['local_protocol']  = $common['local_protocol'] ?? 'tcp';

        return $common;
    }

    public function getRemoteProxy()
    {
        $common = $this->getClientCommon();
        $protocol = $common['tunnel_protocol'];
        $tunnelProtocol = $common['dynamic_tunnel_protocol']; 
        if (in_array($protocol, ['tls', 'wss']) || in_array($tunnelProtocol, ['tls', 'wss'])) {
            return 'https://'.$common['tunnel_host'].':'.$common['tunnel_443_port'];
        } else {
            return 'http://'.$common['tunnel_host'].':'.$common['tunnel_80_port'];
        }
    }

    public function getClientConfigByKey($key)
    {
        return array_merge($this->getClientCommon(), $this->getValue($key));
    }

    public function getHttpPorts()
    {
        $ports = $this->getValue('http.ports');
        if (!$ports) {
            return [];
        }
        $ports = array_filter(array_unique(explode(',', $ports)));
        return $ports;
    }

    public function getTcpIp()
    {
        return $this->getValue('tcp.ip');
    }

    public function getTcpPorts()
    {
        $ports = $this->getValue('tcp.ports');
        if (!$ports) {
            return [];
        }
        $ports = array_filter(array_unique(explode(',', $ports)));
        return $ports;
    }

    public function getUdpIp()
    {
        return $this->getValue('tcp.ip');
    }

    public function getUdpPorts()
    {
        $ports = $this->getValue('udp.ports');
        if (!$ports) {
            return [];
        }
        $ports = array_filter(array_unique(explode(',', $ports)));
        return $ports;
    }
}