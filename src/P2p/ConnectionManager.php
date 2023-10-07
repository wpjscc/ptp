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
        $token = self::$connections[$protocol][$address]['token'] ?: null;

        $connections = self::$connections[$protocol] ?? [];
        $i = 0;
        foreach ($connections as $peerAddress => $value1) {
            $connection = $connections[$peerAddress]['connection'];
            $peerIpLocalAddress = $connections[$peerAddress]['local_address'] ?: null;
            $peerIpWhitelist = $connections[$peerAddress]['ip_whitelist'] ?: null;
            $peerIpBlacklist = $connections[$peerAddress]['ip_blacklist'] ?: null;
            $peerToken = $connections[$peerAddress]['token'] ?: null;

            if ($token || $peerToken) {
                if ($token !== $peerToken) {
                    continue;
                }
            }

            if (!Ip::addressInIpWhitelist($address, $peerIpWhitelist) || Ip::addressInIpBlacklist($address, $peerIpBlacklist)) {
                continue;
            }

            if (!Ip::addressInIpWhitelist($ipLocalAddress, $peerIpWhitelist) || Ip::addressInIpBlacklist($ipLocalAddress, $peerIpBlacklist)) {
                continue;
            }

            if (!Ip::addressInIpWhitelist($peerAddress, $ipWhitelist) || Ip::addressInIpBlacklist($peerAddress, $ipBlacklist)) {
                continue;
            }

            if (!Ip::addressInIpWhitelist($peerIpLocalAddress, $ipWhitelist) || Ip::addressInIpBlacklist($peerIpLocalAddress, $ipBlacklist)) {
                continue;
            }

            var_dump('broadcastAddress', $protocol);

            $connection = $connections[$peerAddress]['connection'];
    
            \React\EventLoop\Loop::addTimer(0.2 * $i, function () use ($connections, $connection, $address, $peerAddress) {
                $connection->write("HTTP/1.1 413 OK\r\nAddress: {$address}\r\n\r\n");
                $connections[$address]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$peerAddress}\r\n\r\n");
            });

            $i++;

            \React\EventLoop\Loop::addTimer(0.2 * $i, function () use ($connections, $connection, $address, $peerAddress) {
                $connection->write("HTTP/1.1 413 OK\r\nAddress: {$connections[$address]['local_address']}\r\n\r\n");
                $connections[$address]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$connections[$peerAddress]['local_address']}\r\n\r\n");
            });
            $i++;

        }
    }
}
