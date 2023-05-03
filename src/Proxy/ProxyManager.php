<?php

namespace Wpjscc\Penetration\Proxy;

class ProxyManager
{
    public static $proxyConnections = [];

    public static $userConnections = [];

    public static function createConnection($uri)
    {
       return new ProxyConnection($uri, [
            'max_connections' => 1000,
            'max_wait_queue' => 50,
            'wait_timeout' => 10,
       ]); 
    }

    public static function getProxyConnection($uri)
    {
        if (isset(static::$proxyConnections[$uri])) {
            return static::$proxyConnections[$uri];
        }
        return static::$proxyConnections[$uri] = static::createConnection($uri);
    }
}