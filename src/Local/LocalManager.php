<?php 

namespace Wpjscc\PTP\Local;


class LocalManager implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    public static $localConnections = [];

    public static function createConnection($uri)
    {
        // todo by uri set config
        return new LocalConnection($uri, [
            'max_connections' => 25,
            'max_wait_queue' => 50,
            'wait_timeout' => 10,
        ]);
    }

    public static function getLocalConnection($uri)
    {
        if (isset(static::$localConnections[$uri])) {
            return static::$localConnections[$uri];
        }
        return static::$localConnections[$uri] = static::createConnection($uri);
    }


    public static function handleLocalConnection($connection, $config, &$buffer, $response)
    {

        static::getLogger()->debug(__FUNCTION__, [
            'tunnel_uuid' => $config['uuid'],
            'dynamic_tunnel_uuid' => $response->getHeaderLine('Uuid'),
        ]);

        // $uri = $config['local_host'];
        // $localPort = $config['local_port'] ?? '';
        // if ($localPort) {
        //     $uri .= ':' . $localPort;
        // }
        $uri = $response->getHeaderLine('Uri');
        LocalManager::getLocalConnection($uri)->pipe($connection, $buffer, $response, $config);
    }
    
}