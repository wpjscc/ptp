<?php 

namespace Wpjscc\PTP\Local;


class LocalManager implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    public static $localConnections = [];


    public static function handleLocalConnection($connection, $config, &$buffer, $response)
    {

        static::getLogger()->debug(__FUNCTION__, [
            'tunnel_uuid' => $config['uuid'],
            'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
        ]);

        $uri = $response->getHeaderLine('Uri');
        static::getLocalConnection($uri, $config)->pipe($connection, $buffer, $response, $config);
    }

    public static function getLocalConnection($uri, $config)
    {
        if (isset(static::$localConnections[$uri])) {
            return static::$localConnections[$uri];
        }
        return static::$localConnections[$uri] = static::createConnection($uri, $config);
    }
    

    public static function createConnection($uri, $config)
    {
        return new LocalConnection($uri, [
            'max_connections' => $config['max_connections'] ?? 10,
            'max_wait_queue' => $config['max_wait_queue'] ?? 50,
            'wait_timeout' => $config['wait_timeout'] ?? 5,
        ]);
    }
    
}