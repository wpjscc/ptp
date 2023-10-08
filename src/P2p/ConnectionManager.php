<?php

namespace Wpjscc\Penetration\P2p;

use Darsyn\IP\Version\IPv4;
use Darsyn\IP\Exception;
use Wpjscc\Penetration\Utils\Ip;

class ConnectionManager
{
    public static $connections = [];

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
                if ($token !== $peerToken) {
                    continue;
                }
            }
            $connection = $connections[$peerAddress]['connection'];
            if (!Ip::addressInIpWhitelist($address, $peerIpWhitelist) || Ip::addressInIpBlacklist($address, $peerIpBlacklist) || !Ip::addressInIpWhitelist($peerAddress, $ipWhitelist) || Ip::addressInIpBlacklist($peerAddress, $ipBlacklist)) {

            } else {
                var_dump('broadcastAddress public address', $protocol);

                \React\EventLoop\Loop::addTimer(2 * $i, function () use ($connections, $connection, $address, $peerAddress) {
                    $connection->write("HTTP/1.1 413 OK\r\nAddress: {$address}\r\n\r\n");
                    $connections[$address]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$peerAddress}\r\n\r\n");
                });
    
                $i++;
            }


            if (!Ip::addressInIpWhitelist($ipLocalAddress, $peerIpWhitelist) || Ip::addressInIpBlacklist($ipLocalAddress, $peerIpBlacklist) || !Ip::addressInIpWhitelist($peerIpLocalAddress, $ipWhitelist) || Ip::addressInIpBlacklist($peerIpLocalAddress, $ipBlacklist)) {

            } else {
                var_dump('broadcastAddress local address', $protocol);
            
                if ($isNeedLocal && $peerIsNeedLocal) {
                    \React\EventLoop\Loop::addTimer(2 * $i, function () use ($connections, $connection, $address, $peerAddress) {
                        $connection->write("HTTP/1.1 413 OK\r\nAddress: {$connections[$address]['local_address']}\r\n\r\n");
                        $connections[$address]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$connections[$peerAddress]['local_address']}\r\n\r\n");
                    });
                    $i++;
                }

            }

        }
    }
}
