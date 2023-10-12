<?php

namespace Wpjscc\Penetration;

use Wpjscc\Penetration\Parse\Ini;


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

    public static function getKey($key, $default = null)
    {
        if (!static::$inis) {
            throw new \Exception('inis is required');
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

    public static function getTcpPorts($inis)
    {
        $ports = static::getValueByKey($inis, 'tcp.ports');
        if (!$ports) {
            return [];
        }
        $ports = array_filter(array_unique(explode(',', $ports)));
        return $ports;
    }
    
    public static function getUdpPorts($inis)
    {
        $ports = static::getValueByKey($inis, 'udp.ports');
        if (!$ports) {
            return [];
        }
        $ports = array_filter(array_unique(explode(',', $ports)));
        return $ports;
    }
}