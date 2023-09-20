<?php

require 'vendor/autoload.php';


use Wpjscc\Penetration\Config;
use Wpjscc\Penetration\Tunnel\Server\Tunnel;
use Wpjscc\Penetration\Server\Http;
use Wpjscc\Penetration\Server\TcpManager;
use Wpjscc\Penetration\Server\Tcp;
use Wpjscc\Penetration\Proxy\ProxyManager;
use Wpjscc\Penetration\Helper;


$inis = Config::getConfig(getParam('--ini-path', './server.ini'));

$httpPort = $inis['common']['http_port'] ?? 8080;
$httpServer = new Http($httpPort);
$httpServer->run();


// tcp server

$tcpManager = TcpManager::create(
    Config::getTcpIp($inis),
    Config::getTcpPorts($inis)
);
$tcpManager->run();


$tunnel = new Tunnel(
    $inis['common'],
);
$tunnel->run();

$startTime = time();

\React\EventLoop\Loop::get()->addPeriodicTimer(5, function () use ($tcpManager) {
    $tcpManager->checkPorts(Config::getTcpPorts(Config::getConfig(getParam('--ini-path', './server.ini'))));
});

\React\EventLoop\Loop::get()->addPeriodicTimer(100, function() use ($startTime){
    $numBytes = gc_mem_caches();
    echo sprintf('%s-%s-%s-%s', Helper::formatTime(time() - $startTime),'after_memory',Helper::formatMemory(memory_get_usage(true)), $numBytes)."\n";
    
    foreach (ProxyManager::$proxyConnections as $uri => $proxyConnection) {
        echo sprintf("%s-%s-%s", 'max_connections', $uri, $proxyConnection->max_connections)."\n";
        echo sprintf("%s-%s-%s", 'current_connections', $uri, $proxyConnection->current_connections)."\n";
        echo sprintf("%s-%s-%s", 'max_wait_queue', $uri, $proxyConnection->max_wait_queue)."\n";
        echo sprintf("%s-%s-%s", 'remote:wait:queue', $uri, $proxyConnection->wait_queue->count())."\n";
    }

    foreach (ProxyManager::$remoteTunnelConnections as $uri => $remoteTunnelConnection) {
        echo sprintf("%s-%s-%s", 'remote:tunnel:connection', $uri, $remoteTunnelConnection->count())."\n";
    }

    foreach (ProxyManager::$remoteDynamicConnections as $uri => $remoteDynamicConnection) {
        echo sprintf("%s-%s-%s", 'remote:dynamic:connection:defered', $uri, $remoteDynamicConnection->count())."\n";
    }
    echo "\n";
});


function getParam($key, $default = null){
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, $key) !==false){
            return explode('=', $arg)[1];
        }
    }
    return $default;
}
