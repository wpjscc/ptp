<?php

namespace Wpjscc\Penetration\P2p;

use Wpjscc\Penetration\Utils\Ip;
use Wpjscc\Penetration\Utils\PingPong;

class ConnectionManager
{
    public static $connections = [];

    protected static $queues = [];

    public static function broadcastAddress($protocol, $address, $code = 413, $peer = '')
    {
    
        $ipLocalAddress = self::$connections[$protocol][$address]['local_address'] ?: null;
        $ipWhitelist = self::$connections[$protocol][$address]['ip_whitelist'] ?: null;
        $ipBlacklist = self::$connections[$protocol][$address]['ip_blacklist'] ?: null;
        $isNeedLocal = self::$connections[$protocol][$address]['is_need_local'] ?: null;
        $uuid = self::$connections[$protocol][$address]['uuid'] ?: null;
        $tryTcp = self::$connections[$protocol][$address]['try_tcp'] ?: '';
        $token = self::$connections[$protocol][$address]['token'] ?: null;

        $connections = [];
        if ($peer) {
            $peerConnection = self::$connections[$protocol][$peer] ?? [];
            if ($peerConnection) {
                $connections[$peer] = $peerConnection;
            }
        } else {
            $connections = self::$connections[$protocol] ?? [];
        }


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
            $peerUuid = $connections[$peerAddress]['uuid'] ?: null;
            $peerToken = $connections[$peerAddress]['token'] ?: null;

            // 同一进程内的不推送
            if ($peerUuid === $uuid) {
                echo "broadcastAddress same uuid: {$address}-{$uuid} ====> {$peerAddress}-{$peerUuid}\n";
                continue;
            }

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

                $f = function () use ($protocol, $address, $peerAddress, $code) {
                    if (!isset(ConnectionManager::$connections[$protocol][$address]) || !isset(ConnectionManager::$connections[$protocol][$peerAddress])) {
                        return false;
                    }
                    $peerConnection = ConnectionManager::$connections[$protocol][$peerAddress]['connection'];
                    $connection = ConnectionManager::$connections[$protocol][$address]['connection'];
                    PingPong::allPingPong([
                        $peerConnection,
                        $connection,
                    ], 1.8)->then(function () use ($peerConnection, $connection, $address, $peerAddress, $protocol, $code) {
                        if (!isset(ConnectionManager::$connections[$protocol][$address]) || !isset(ConnectionManager::$connections[$protocol][$peerAddress])) {
                            return false;
                        }
                        $pubchAddress = $address;
                        $peerPunchAddress = $peerAddress;
                        if ($code == 418) {
                            if (!isset(ConnectionManager::$connections[$protocol][$address]['peers'][$peerAddress]['public']) || !isset(ConnectionManager::$connections[$protocol][$peerAddress]['peers'][$address]['public'])) {
                                return false;
                            }
                            if (empty(ConnectionManager::$connections[$protocol][$address]['peers'][$peerAddress]['public']) || empty(ConnectionManager::$connections[$protocol][$peerAddress]['peers'][$address]['public'])) {
                                return false;
                            }

                            $peerPunchAddress = array_key_first(ConnectionManager::$connections[$protocol][$address]['peers'][$peerAddress]['public']);
                            unset(ConnectionManager::$connections[$protocol][$address]['peers'][$peerAddress]['public'][$peerPunchAddress]);
                            $pubchAddress = array_key_first(ConnectionManager::$connections[$protocol][$peerAddress]['peers'][$address]['public']);
                            unset(ConnectionManager::$connections[$protocol][$peerAddress]['peers'][$address]['public'][$pubchAddress]);

                        } 
                        $tryTcp = ConnectionManager::$connections[$protocol][$address]['try_tcp'] ?: '0';
                        $peerConnection->write("HTTP/1.1 {$code} OK\r\n\Try-tcp: {$tryTcp}\r\nAddress: {$pubchAddress}\r\nRemote-Peer-Address: $address\r\n\r\n");
                        $peerTryTcp = ConnectionManager::$connections[$protocol][$peerAddress]['try_tcp'] ?: '0';
                        $connection->write("HTTP/1.1 {$code} OK\r\nTry-tcp: {$peerTryTcp}\r\nAddress: {$peerPunchAddress}\r\nRemote-Peer-Address: $peerAddress\r\n\r\n");

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
                    $f = function () use ($protocol, $address, $peerAddress, $code) {
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
                        ], 1)->then(function () use ($peerConnection, $connection, $protocol,$address, $peerAddress, $localAddress, $peerLocalAddress, $tryTcp, $peerTryTcp, $code) {
                            if (!isset(ConnectionManager::$connections[$protocol][$address]) || !isset(ConnectionManager::$connections[$protocol][$peerAddress])) {
                                return false;
                            }

                            $pubchLocalAddress = $localAddress;
                            $punchPeerLocalAddress = $peerLocalAddress;
                            if ($code == 418) {
                                if (!isset(ConnectionManager::$connections[$protocol][$address]['peers'][$peerAddress]['local']) || !isset(ConnectionManager::$connections[$protocol][$peerAddress]['peers'][$address]['local'])) {
                                    return false;
                                }
                                if (empty(ConnectionManager::$connections[$protocol][$address]['peers'][$peerAddress]['local']) || empty(ConnectionManager::$connections[$protocol][$peerAddress]['peers'][$address]['local'])) {
                                    return false;
                                }
    
                                $punchPeerLocalAddress = array_key_first(ConnectionManager::$connections[$protocol][$address]['peers'][$peerAddress]['local']);
                                unset(ConnectionManager::$connections[$protocol][$address]['peers'][$peerAddress]['local'][$punchPeerLocalAddress]);
    
                                $pubchLocalAddress = array_key_first(ConnectionManager::$connections[$protocol][$peerAddress]['peers'][$address]['local']);
                                unset(ConnectionManager::$connections[$protocol][$peerAddress]['peers'][$address]['local'][$pubchLocalAddress]);
                            } 

                            $peerConnection->write("HTTP/1.1 {$code} OK\r\nTry-tcp: {$tryTcp}\r\nAddress: {$pubchLocalAddress}\r\nCurrent-Address: $punchPeerLocalAddress\r\nRemote-Peer-Address: $address\r\n\r\n");
                            $connection->write("HTTP/1.1 {$code} OK\r\nTry-tcp: {$peerTryTcp}\r\nAddress: {$punchPeerLocalAddress}\r\nCurrent-Address: $pubchLocalAddress\r\nRemote-Peer-Address: $peerAddress\r\n\r\n");

                            echo "broadcastAddress local address: [{$address} {$pubchLocalAddress}] ====> [{$peerAddress} {$punchPeerLocalAddress}]\n";
                            echo "broadcastAddress local address: [{$peerAddress} {$punchPeerLocalAddress}] ====> [{$address} {$pubchLocalAddress}]\n";

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


    public static function broadcastAddressAndPeer($protocol, $address, $peer)
    {
        static::broadcastAddress($protocol, $address, 418, $peer);
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
