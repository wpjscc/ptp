<?php

namespace Wpjscc\PTP;

use Wpjscc\PTP\Parse\Ini;


class Config
{
    static $inis;
    static $iniPath;

    public static function getConfig($iniPath)
    {
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
        
        $inis = (new Ini)->parse(file_get_contents($iniPath));
        static::$inis = $inis;
        static::$iniPath = $iniPath;
        return $inis;
    }
    
    public static function getClientConfigByKey($key)
    {
        return array_merge(Config::getClientCommon(), Config::getKey($key));
    }

    public static function getClientCommon()
    {
        $common = static::getKey('common');
        $common['timeout']  = $common['timeout'] ?? 6;
        $common['single_tunnel']  = $common['single_tunnel'] ?? 0;
        $common['pool_count']  = $common['pool_count'] ?? 1;
        $common['tunnel_protocol']  = $common['tunnel_protocol'] ?? 'tcp';
        $common['dynamic_tunnel_protocol']  = $common['dynamic_tunnel_protocol'] ?? 'tcp';
        $common['local_protocol']  = $common['local_protocol'] ?? 'tcp';

        return $common;
    }

    public static function getRemoteProxy()
    {
        $common = static::getClientCommon();
        $protocol = $common['tunnel_protocol'];
        $tunnelProtocol = $common['dynamic_tunnel_protocol']; 
        if (in_array($protocol, ['tls', 'wss']) || in_array($tunnelProtocol, ['tls', 'wss'])) {
            return 'https://'.$common['tunnel_host'].':'.$common['tunnel_443_port'];
        } else {
            return 'http://'.$common['tunnel_host'].':'.$common['tunnel_80_port'];
        }
    }

    public static function getKey($key, $default = null)
    {
        if (!static::$inis) {
            throw new \Exception('inis is required');
        }

        if (!$key) {
            return static::$inis;
        }

        $value = static::getValueByKey(static::$inis, $key);

        if ($value === null) {
            return $default;
        }

        return $value;
    }

    public static function getValueByKey($inis, $key)
    {
        $keys = explode('.', $key);

        $value = $inis;
        foreach ($keys as $key) {
            $value = $value[$key] ?? null;
        }
        return $value;
    }

    public static function getTcpIp($inis)
    {
        return static::getValueByKey($inis, 'tcp.ip');
    }

    public static function getUdpIp($inis)
    {
        return static::getValueByKey($inis, 'udp.ip');
    }

    public static function getTcpPorts()
    {
        $ports = static::getKey('tcp.ports');
        if (!$ports) {
            return [];
        }
        $ports = array_filter(array_unique(explode(',', $ports)));
        return $ports;
    }
    
    public static function getUdpPorts()
    {
        $ports = static::getKey('udp.ports');
        if (!$ports) {
            return [];
        }
        $ports = array_filter(array_unique(explode(',', $ports)));
        return $ports;
    }
}