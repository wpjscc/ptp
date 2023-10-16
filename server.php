<?php

require 'vendor/autoload.php';

use Clue\React\Socks\Server;
use Wpjscc\PTP\Config;
use Wpjscc\PTP\Tunnel\Server\Tunnel;
use Wpjscc\PTP\Server\Http;
use Wpjscc\PTP\Server\HttpManager;
use Wpjscc\PTP\Server\TcpManager;
use Wpjscc\PTP\Server\UdpManager;
use Wpjscc\PTP\Server\ServerManager;
use Wpjscc\PTP\Proxy\ProxyManager;
use Wpjscc\PTP\Log\LogManager;
use Psr\Log\LogLevel;
use Wpjscc\PTP\Helper;
use Wpjscc\PTP\P2p\ConnectionManager;


\Wpjscc\PTP\Environment::$type = 'server';

if (getParam('-vvv')) {
    LogManager::$logLevels = [
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::DEBUG,
        LogLevel::EMERGENCY,
        LogLevel::ERROR,
        LogLevel::INFO,
        LogLevel::WARNING,
        LogLevel::NOTICE,
    
    ];
}

LogManager::setLogger(new \Wpjscc\PTP\Log\EchoLog());

ServerManager::instance('server')->run();

$startTime = time();


ConnectionManager::consumeQueues(1);

\React\EventLoop\Loop::get()->addPeriodicTimer(5, function () {
    
    $httpManager = HttpManager::instance('server');
    $tcpManager = TcpManager::instance('server');
    $udpManager = UdpManager::instance('server');
    $uris = array_keys(ProxyManager::$remoteTunnelConnections);
    $info = ServerManager::instance('server')->getInfo();
    // server port
    echo "======> PTP Version -> " . $info['version'] . PHP_EOL;
    echo "======> tunnel server host -> ". $info['tunnel_host'] . PHP_EOL;
    echo "======> tunnel server [80] port listen at -> ". $info['tunnel_80_port'] . PHP_EOL;
    echo "======> tunnel server [443] port listen at -> ". $info['tunnel_443_port'] . PHP_EOL;
   // http ports
   echo "=======> http ports listen at -> {$httpManager->getIp()}:". implode(', ', $httpManager->getPorts()) . PHP_EOL;
   // tcp ports
   echo "=======> tcp ports listen at -> {$tcpManager->getIp()}:". implode(', ', $tcpManager->getPorts()) . PHP_EOL;
   // udp ports
   echo "=======> udp ports listen at -> {$udpManager->getIp()}:". implode(', ', $udpManager->getPorts()) . PHP_EOL;

    echo "======> uris -> ". implode(', ', $uris) . PHP_EOL.PHP_EOL;
});

\React\EventLoop\Loop::get()->addPeriodicTimer(30, function() use ($startTime){
    $numBytes = gc_mem_caches();
    echo "\n";
    echo sprintf('%s-%s-%s-%s', Helper::formatTime(time() - $startTime),'after_memory',Helper::formatMemory(memory_get_usage(true)), $numBytes)."\n";
    
    foreach (ProxyManager::$proxyConnections as $uri => $proxyConnection) {
        echo sprintf("%s-%s-%s", 'max_connections', $uri, $proxyConnection->getMaxConnectins())."\n";
        echo sprintf("%s-%s-%s", 'current_connections', $uri, $proxyConnection->getCurrentConnections())."\n";
        echo sprintf("%s-%s-%s", 'current_connection_uuids', $uri, implode(',', array_keys($proxyConnection->connections)))."\n";
        echo sprintf("%s-%s-%s", 'max_wait_queue', $uri, $proxyConnection->getMaxWaitQueue())."\n";
        echo sprintf("%s-%s-%s", 'remote:wait:queue', $uri, $proxyConnection->getWaitQueueCount())."\n";
    }
   
    foreach (ProxyManager::$remoteTunnelConnections as $uri => $remoteTunnelConnection) {
        echo sprintf("%s-%s-%s", 'remote:tunnel:connection', $uri, $remoteTunnelConnection->count())."\n";
    }

    foreach (ProxyManager::$remoteDynamicConnections as $uri => $remoteDynamicConnection) {
        echo sprintf("%s-%s-%s", 'remote:dynamic:connection:defered', $uri, $remoteDynamicConnection->count())."\n";
    }
    echo "\n";
});

