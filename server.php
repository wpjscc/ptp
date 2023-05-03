<?php

require 'vendor/autoload.php';


use Wpjscc\Penetration\ClientServer;
use Wpjscc\Penetration\UserServer;
use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Proxy\ProxyManager;
use Wpjscc\Penetration\Helper;


$clientServer = new ClientServer();
$userServer = new UserServer();


$clientServer->run();
$userServer->run();

$startTime = time();

\React\EventLoop\Loop::get()->addPeriodicTimer(1000, function() use ($startTime){
    $numBytes = gc_mem_caches();
    echo sprintf('%s-%s-%s-%s', Helper::formatTime(time() - $startTime),'after_memory',Helper::formatMemory(memory_get_usage(true)), $numBytes)."\n";
    
    foreach (ProxyManager::$proxyConnections as $uri => $proxyConnection) {
        echo sprintf("%s-%s-%s", 'remote:dynamic:connection', $uri, $proxyConnection->idle_connections->count())."\n";
        echo sprintf("%s-%s-%s", 'max_connections', $uri, $proxyConnection->max_connections)."\n";
        echo sprintf("%s-%s-%s", 'current_connections', $uri, $proxyConnection->current_connections)."\n";
        echo sprintf("%s-%s-%s", 'max_wait_queue', $uri, $proxyConnection->max_wait_queue)."\n";
        echo sprintf("%s-%s-%s", 'remote:wait:queue', $uri, $proxyConnection->wait_queue->count())."\n";
    }

    foreach (ClientManager::$remoteTunnelConnections as $uri => $remoteTunnelConnection) {
        echo sprintf("%s-%s-%s", 'remote:tunnel:connection', $uri, $remoteTunnelConnection->count())."\n";
    }

    foreach (ClientManager::$remoteDynamicConnections as $uri => $remoteDynamicConnection) {
        echo sprintf("%s-%s-%s", 'remote:dynamic:connection:defered', $uri, $remoteDynamicConnection->count())."\n";
    }
    echo "\n";
});