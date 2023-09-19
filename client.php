<?php

require 'vendor/autoload.php';

use Wpjscc\Penetration\Client\ClientManager;


ClientManager::createLocalTunnelConnection(
    $tunnelProtocol = getParam('--tunnel-protocol'),
    $localHost = getParam('--local-host'),
    $localPort = getParam('--local-port'),
    $domain = getParam('--domain'),
    $token = getParam('--token'),
    $remoteHost = getParam('--remote-host'),
    $remotePort = getParam('--remote-port'),
    $remoteTls = getParam('--remote-tls') ? true : false,
    $localTls = getParam('--local-tls') ? true : false,
    $localProxy = getParam('--local-proxy'),
    $localReplaceHost = getParam('--local-replace-host') ? true : false,
);

function getParam($key, $default = null){
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, $key) !==false){
            return explode('=', $arg)[1];
        }
    }
    return $default;
}
