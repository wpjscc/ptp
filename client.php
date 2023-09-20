<?php

require 'vendor/autoload.php';

use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Parse\Ini;

$iniPath = getParam('--ini-path', './client.ini');

if (!$iniPath || !file_exists($iniPath)) {
    throw new \Exception('iniPath is required');
}

$inis = (new Ini)->parse(file_get_contents($iniPath));

ClientManager::createLocalTunnelConnection(
    $inis
);

function getParam($key, $default = null){
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, $key) !==false){
            return explode('=', $arg)[1];
        }
    }
    return $default;
}
