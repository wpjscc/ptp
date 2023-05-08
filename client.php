<?php

require 'vendor/autoload.php';

use Wpjscc\Penetration\Client\ClientManager;


ClientManager::createLocalTunnelConnection(
    $localHost = getParam('--local-host'),
    $localPort = getParam('--local-port'),
    $domain = getParam('--domain'),
    $token = getParam('--token'),
    $remoteHost = getParam('--remote-host'),
    $remotePort = getParam('--remote-port')
);

function getParam($key, $default = null){
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, $key) !==false){
            return explode('=', $arg)[1];
        }
    }
    return $default;
}
