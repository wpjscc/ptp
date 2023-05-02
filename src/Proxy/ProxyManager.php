<?php

namespace Wpjscc\Penetration\Proxy;

class ProxyManager
{
    public $proxyConnections = [];

    public static function createConnection($uri)
    {
       return new ProxyConnection($uri); 
    }

    public static function getProxyConnection($uri, $force = false)
    {
        if (isset(static::$proxyConnections)) {
            return static::$proxyConnections[$uri];
        }
        if ($force) {
            return  false;
        }
        return static::$proxyConnections[$uri] = static::createConnection($uri);
    }
}