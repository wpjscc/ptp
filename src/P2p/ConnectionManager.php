<?php

namespace Wpjscc\Penetration\P2p;

use Darsyn\IP\Version\IPv4;
use Darsyn\IP\Exception;

class ConnectionManager
{
    public static $connections = [];

    public static function broadcastAddress($protocol, $address)
    {
        $ip = strpos($address, '://') === false ? explode(':', $address)[0] : explode(':', explode('://', $address)[1])[0] ;

        $currentAddress = IPv4::factory($ip);
        $currentipRange = self::$connections[$protocol][$address]['ip_range'] ?? [];
        $connections = self::$connections[$protocol] ?? [];
        foreach ($connections as $peerAddress => $value1) {

            $ipRange = $connections[$peerAddress]['ip_range'] ?? [];

            if (!empty($ipRange)) {
                $isInIpRange = false;
                try {
                    foreach ($ipRange as $range) {
                        $range = explode('/', $range);
                        $rangeIp = IPv4::factory($range[0]);
                        $rangeCidr = $range[1] ?? 32;
                        if ($currentAddress->inRange($rangeIp, $rangeCidr)) {
                            $isInIpRange = true;
                            break;
                        }
                    }
                } catch (Exception\InvalidIpAddressException $e) {
                    echo 'The IP address supplied is invalid!';
                    continue;
                }

                if (!$isInIpRange) {
                    echo "current not in peer ip range" . PHP_EOL;
                    continue;
                }
            }

            if (!empty($currentipRange)) {
                $isInIpRange = false;
                try {
                    $peerIp = strpos($peerAddress, '://') === false ? explode(':', $peerAddress)[0] :explode(':', explode('://', $peerAddress)[1])[0];
                    $peerAddress = IPv4::factory($peerIp);
                    foreach ($currentipRange as $range) {
                        $range = explode('/', $range);
                        $rangeIp = IPv4::factory($range[0]);
                        $rangeCidr = $range[1] ?? 32;
                        if ($peerAddress->inRange($rangeIp, $rangeCidr)) {
                            $isInIpRange = true;
                            break;
                        }
                    }
                } catch (Exception\InvalidIpAddressException $e) {
                    echo 'The IP address supplied is invalid!';
                    continue;
                }

                if (!$isInIpRange) {
                    echo "peer ip not in current ip range" . PHP_EOL;
                    echo "peer ip $peerAddress" . PHP_EOL;
                    echo "current ip range " . json_encode($currentipRange) . PHP_EOL;
                    continue;
                }
            }

            var_dump('broadcastAddress', $protocol);

            $connection = $connections[$peerAddress]['connection'];
            // $connection->write(json_encode([
            //     'type' => 'broadcast_address',
            //     'address' => $connections[$address]['local_address']
            // ]));
            $connection->write("HTTP/1.1 413 OK\r\nAddress: {$connections[$address]['local_address']}\r\n\r\n");
            // $connection->write(json_encode([
            //     'type' => 'broadcast_address',
            //     'address' => $address
            // ]));
            $connection->write("HTTP/1.1 413 OK\r\nAddress: {$address}\r\n\r\n");

            // $connections[$address]['connection']->write(json_encode([
            //     'type' => 'broadcast_address',
            //     'address' => $connections[$peerAddress]['local_address'],
            // ]));
            $connections[$address]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$connections[$peerAddress]['local_address']}\r\n\r\n");

            // $connections[$address]['connection']->write(json_encode([
            //     'type' => 'broadcast_address',
            //     'address' => $peerAddress
            // ]));
            $connections[$address]['connection']->write("HTTP/1.1 413 OK\r\nAddress: {$peerAddress}\r\n\r\n");
        }
    }
}
