<?php

namespace Wpjscc\Penetration;

use Wpjscc\Penetration\Parse\Ini;


class Config
{
    public static function getConfig($iniPath)
    {
        if (!$iniPath || !file_exists($iniPath)) {
            throw new \Exception('iniPath is required');
        }
        
        $inis = (new Ini)->parse(file_get_contents($iniPath));

        return $inis;
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

    public static function getTcpPorts($inis)
    {
        $ports = static::getValueByKey($inis, 'tcp.ports');
        $ports = array_filter(array_unique(explode(',', $ports)));
        return $ports;
    }
}