<?php

require 'vendor/autoload.php';


use Wpjscc\PTP\Config;
use Wpjscc\PTP\Tunnel\Server\Tunnel;
use Wpjscc\PTP\Server\Http;
use Wpjscc\PTP\Server\TcpManager;
use Wpjscc\PTP\Server\UdpManager;
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


$inis = Config::getConfig(getParam('--ini-path', './ptps.ini'));

$inis['common']['tunnel_protocol'] = $inis['common']['tunnel_protocol'] ?? 'tcp';
$server80Port = $inis['common']['tunnel_80_port'] ?? '80';
$server443Port = $inis['common']['tunnel_443_port'] ?? '';

// http server
$httpPort = $inis['common']['http_port'] ?? '';
if ($httpPort) {
    $httpServer = new Http($httpPort);
    $httpServer->run();
}


// tcp server

$tcpManager = TcpManager::create(
    Config::getTcpIp($inis),
    Config::getTcpPorts($inis)
);
$tcpManager->run();

// udp server
$udpManager = UdpManager::create(
    Config::getUdpIp($inis),
    Config::getUdpPorts($inis)
);
$udpManager->run();


$tunnel = new Tunnel(
    $inis['common'],
    $inis['cert'] ?? []
);
$tunnel->run();

$startTime = time();


ConnectionManager::consumeQueues(1);

\React\EventLoop\Loop::get()->addPeriodicTimer(5, function () use ($tcpManager, $udpManager, $httpPort, $server80Port, $server443Port) {
    $tcpManager->checkPorts(Config::getTcpPorts(Config::getConfig(getParam('--ini-path', './ptps.ini'))));
    $udpManager->checkPorts(Config::getUdpPorts(Config::getConfig(getParam('--ini-path', './ptps.ini'))));
    $uris = array_keys(ProxyManager::$remoteTunnelConnections);
    // server port
    echo "======> tunnel server [80] port listen at -> ". $server80Port . PHP_EOL;
    echo "======> tunnel server [443] port listen at -> ". $server443Port . PHP_EOL;
    echo "======> http and proxy listen at -> $httpPort" . PHP_EOL;
    // tcp ports
    echo "======> tcp ports listen at -> {$tcpManager->getIp()}:". implode(', ', $tcpManager->getPorts()) . PHP_EOL;
    // udp ports
    echo "======> udp ports listen at -> {$udpManager->getIp()}:". implode(', ', $udpManager->getPorts()) . PHP_EOL;

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

