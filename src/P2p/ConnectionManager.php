<?php

namespace Wpjscc\Penetration\P2p;

use Darsyn\IP\Version\IPv4;
use Darsyn\IP\Exception;
use Wpjscc\Penetration\Utils\Ip;

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
            $connection = $connections[$peerAddress]['connection'];
            if (!Ip::addressInIpWhitelist($address, $peerIpWhitelist) || Ip::addressInIpBlacklist($address, $peerIpBlacklist) || !Ip::addressInIpWhitelist($peerAddress, $ipWhitelist) || Ip::addressInIpBlacklist($peerAddress, $ipBlacklist)) {

            } else {
                var_dump('broadcastAddress public address', $protocol);

                $f = function () use ($protocol, $address, $peerAddress) {
                    if (!isset(ConnectionManager::$connections[$protocol][$address]) || !isset(ConnectionManager::$connections[$protocol][$peerAddress])) {
                        return false;
                    }

                    ConnectionManager::$connections[$protocol][$peerAddress]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$address}\r\n\r\n");
                    ConnectionManager::$connections[$protocol][$address]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$peerAddress}\r\n\r\n");

                    echo "broadcastAddress public address: {$address} ====> {$peerAddress}\n";
                    echo "broadcastAddress public address: {$peerAddress} ====> {$address}\n";
                    return true;
                };
                array_push(self::$queues, $f);
                // \React\EventLoop\Loop::addTimer(2 * $i, function () use ($connections, $connection, $address, $peerAddress) {
                //     $connection->write("HTTP/1.1 413 OK\r\nAddress: {$address}\r\n\r\n");
                //     $connections[$address]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$peerAddress}\r\n\r\n");
                // });
    
                // $i++;
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
                        $peerLocalAddress = ConnectionManager::$connections[$protocol][$peerAddress]['local_address'];
                        ConnectionManager::$connections[$protocol][$peerAddress]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$localAddress}\r\n\r\n");
                        ConnectionManager::$connections[$protocol][$address]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$peerLocalAddress}\r\n\r\n");

                        echo "broadcastAddress local address: [{$address} {$localAddress}] ====> [{$peerAddress} {$peerLocalAddress}]\n";
                        echo "broadcastAddress local address: [{$peerAddress} {$peerLocalAddress}] ====> [{$address} {$localAddress}]\n";

                        return true;
                    };
                    array_push(self::$queues, $f);
                    // \React\EventLoop\Loop::addTimer(2 * $i, function () use ($connections, $connection, $address, $peerAddress) {
                    //     $connection->write("HTTP/1.1 413 OK\r\nAddress: {$connections[$address]['local_address']}\r\n\r\n");
                    //     $connections[$address]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$connections[$peerAddress]['local_address']}\r\n\r\n");
                    // });
                    // $i++;
                }

            }

        }
    }
    // 消费queues
    public static function consumeQueues($timer = 2)
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
