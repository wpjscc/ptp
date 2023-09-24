<?php

require 'vendor/autoload.php';

use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Log\LogManager;
use Psr\Log\LogLevel;
use Wpjscc\Penetration\Config;

$config = Config::getConfig(getParam('--ini-path', './client.ini'));

LogManager::$logLevels = [
    // LogLevel::ALERT,
    // LogLevel::CRITICAL,
    // LogLevel::DEBUG,
    // LogLevel::EMERGENCY,
    LogLevel::ERROR,
    // LogLevel::INFO,
    // LogLevel::WARNING,
    LogLevel::NOTICE,

];
LogManager::setLogger(new \Wpjscc\Penetration\Log\EchoLog());
ClientManager::createLocalTunnelConnection($config);

function getParam($key, $default = null){
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, $key) !==false){
            return explode('=', $arg)[1];
        }
    }
    return $default;
}
