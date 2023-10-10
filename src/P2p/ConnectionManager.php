<?php

namespace Wpjscc\Penetration\P2p;

use Wpjscc\Penetration\Utils\Ip;
use Wpjscc\Penetration\Utils\PingPong;

class ConnectionManager
{
    public static $connections = [];

    protected static $queues = [];

    public static function broadcastAddress($protocol, $address)
    {
    
        $ipLocalAddress = self::$connections[$protocol][$address]['local_address'] ?: null;
        $ipWhitelist = self::$connections[$protocol][$address]['ip_whitelist'] ?: null;
        $ipBlacklist = self::$connections[$protocol][$address]['ip_blacklist'] ?: null;
        $isNeedLocal = self::$connections[$protocol][$address]['is_need_local'] ?: null;
        $tryTcp = self::$connections[$protocol][$address]['try_tcp'] ?: '';
        $token = self::$connections[$protocol][$address]['token'] ?: null;

        $connections = self::$connections[$protocol] ?? [];
        $i = 0;
        foreach ($connections as $peerAddress => $value1) {

            if ($peerAddress === $address) {
                continue;
            }

            $connection = $connections[$peerAddress]['connection'];
            $peerIpLocalAddress = $connections[$peerAddress]['local_address'] ?: null;
            $peerIpWhitelist = $connections[$peerAddress]['ip_whitelist'] ?: null;
            $peerIpBlacklist = $connections[$peerAddress]['ip_blacklist'] ?: null;
            $peerIsNeedLocal = $connections[$peerAddress]['is_need_local'] ?: null;
            $peerToken = $connections[$peerAddress]['token'] ?: null;

            if ($token || $peerToken) {
                $tokens = explode(',', $token);
                $peerTokens = explode(',', $peerToken);
                if (empty(array_values(array_filter(array_intersect($tokens, $peerTokens))))) {
                    continue;
                }
            }

            if (!Ip::addressInIpWhitelist($address, $peerIpWhitelist) || Ip::addressInIpBlacklist($address, $peerIpBlacklist) || !Ip::addressInIpWhitelist($peerAddress, $ipWhitelist) || Ip::addressInIpBlacklist($peerAddress, $ipBlacklist)) {

            } else {
                var_dump('broadcastAddress public address', $protocol);

                $f = function () use ($protocol, $address, $peerAddress) {
                    if (!isset(ConnectionManager::$connections[$protocol][$address]) || !isset(ConnectionManager::$connections[$protocol][$peerAddress])) {
                        return false;
                    }
                    $peerConnection = ConnectionManager::$connections[$protocol][$peerAddress]['connection'];
                    $connection = ConnectionManager::$connections[$protocol][$address]['connection'];
                    PingPong::allPingPong([
                        $peerConnection,
                        $connection,
                    ], 1.8)->then(function () use ($peerConnection, $connection, $address, $peerAddress, $protocol) {

                        $tryTcp = ConnectionManager::$connections[$protocol][$address]['try_tcp'] ?: '0';
                        $peerConnection->write("HTTP/1.1 413 OK\r\n\Try-tcp: {$tryTcp}\r\nAddress: {$address}\r\n\r\n");
                        $peerTryTcp = ConnectionManager::$connections[$protocol][$peerAddress]['try_tcp'] ?: '0';
                        $connection->write("HTTP/1.1 413 OK\r\nTry-tcp: {$peerTryTcp}\r\nAddress: {$peerAddress}\r\n\r\n");

                        echo "broadcastAddress public address: {$address} ====> {$peerAddress}\n";
                        echo "broadcastAddress public address: {$peerAddress} ====> {$address}\n";

                    }, function ($e) {

                        echo $e->getMessage() . PHP_EOL;
                    });
                    
                    return true;
                };
                array_push(self::$queues, $f);
            }


            if (!Ip::addressInIpWhitelist($ipLocalAddress, $peerIpWhitelist) || Ip::addressInIpBlacklist($ipLocalAddress, $peerIpBlacklist) || !Ip::addressInIpWhitelist($peerIpLocalAddress, $ipWhitelist) || Ip::addressInIpBlacklist($peerIpLocalAddress, $ipBlacklist)) {

            } else {
                var_dump('broadcastAddress local address', $protocol);
            
                if ($isNeedLocal && $peerIsNeedLocal) {
                    $f = function () use ($protocol, $address, $peerAddress) {
                        if (!isset(ConnectionManager::$connections[$protocol][$address]) || !isset(ConnectionManager::$connections[$protocol][$peerAddress])) {
                            return false;
                        }
                        $localAddress = ConnectionManager::$connections[$protocol][$address]['local_address'];
                        $tryTcp = ConnectionManager::$connections[$protocol][$address]['try_tcp'] ?: '0';
                        $peerLocalAddress = ConnectionManager::$connections[$protocol][$peerAddress]['local_address'];
                        $peerTryTcp = ConnectionManager::$connections[$protocol][$peerAddress]['try_tcp'] ?: '0';
                        
                        $peerConnection = ConnectionManager::$connections[$protocol][$peerAddress]['connection'];
                        $connection = ConnectionManager::$connections[$protocol][$address]['connection'];

                        PingPong::allPingPong([
                            $peerConnection,
                            $connection,
                        ], 1)->then(function () use ($peerConnection, $connection, $address, $peerAddress, $localAddress, $peerLocalAddress, $tryTcp, $peerTryTcp) {

                            $peerConnection->write("HTTP/1.1 413 OK\r\nTry-tcp: {$tryTcp}\r\nAddress: {$localAddress}\r\n\r\n");
                            $connection->write("HTTP/1.1 413 OK\r\nTry-tcp: {$peerTryTcp}\r\nAddress: {$peerLocalAddress}\r\n\r\n");

                            echo "broadcastAddress local address: [{$address} {$localAddress}] ====> [{$peerAddress} {$peerLocalAddress}]\n";
                            echo "broadcastAddress local address: [{$peerAddress} {$peerLocalAddress}] ====> [{$address} {$localAddress}]\n";

                        }, function ($e) {
                            echo $e->getMessage() . PHP_EOL;
                        });
                        return true;
                    };
                    array_push(self::$queues, $f);
                }

            }

        }
    }
    // 消费queues
    public static function consumeQueues($timer = 1)
    {
        
        \React\EventLoop\Loop::addPeriodicTimer($timer, function () {
            while (count(self::$queues) > 0) {
                $f = array_shift(self::$queues);
                $state = $f();
                if ($state) {
                    break;
                }
            }
        });
    }
}
