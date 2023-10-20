<?php


namespace Wpjscc\PTP\P2p\Client;

use Wpjscc\PTP\Proxy\ProxyManager;
use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Utils\Ip;
use RingCentral\Psr7;

class PeerManager implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    static $uuid;

    protected static $peers = [];
    public static $tcpPeers = [];
    protected static $peereds = [];
    // protected static $tcpPeereds = [];
    protected static $timers = [];

    protected static $connections = [];
    protected static $localAddrToRemoteAddr = [];

    protected static $peerAddrToRemoteAddr = [];


    public static function addPeer($address, $peer)
    {
        $peer = (array) $peer;

        if (!isset(static::$peers[$address])) {
            static::$peers[$address] = [];
        }


        $diff = array_diff($peer, static::$peers[$address]);

        if (!empty($diff)) {
            static::$peers[$address] = array_values(array_merge(static::$peers[$address], $diff));
        }

        return static::$peers[$address];
    }

    public static function removePeer($address, $peer)
    {
        $peer = (array) $peer;

        if (!isset(static::$peers[$address])) {
            return [];
        }
        return static::$peers[$address] = array_values(array_diff(static::$peers[$address], $peer));
    }

    public static function getPeers($address)
    {
        return static::$peers[$address] ?? [];
    }


    public static function addTcpPeer($address, $peer)
    {
        $address = Ip::getIpAndPort($address);
        $peer = Ip::getIpAndPort($peer);

        return static::$tcpPeers = array_values(array_unique(array_merge(static::$tcpPeers, [
            $address, $peer
        ])));
    }

    public static function hasTcpPeer($address, $peer)
    {
        $address = Ip::getIpAndPort($address);
        $peer = Ip::getIpAndPort($peer);

        return !empty(array_intersect([$address, $peer], static::$tcpPeers));
    }

    public static function removeTcpPeer($address, $peer)
    {
        $address = Ip::getIpAndPort($address);
        $peer = Ip::getIpAndPort($peer);

        return static::$tcpPeers = array_values(array_diff(static::$tcpPeers, [
            $address, $peer
        ]));
    }

    public static function getTcpPeers($address)
    {
        return static::$tcpPeers;
    }

    public static function addTimer($address, $peer, $data)
    {

        if (!isset(static::$timers[$address])) {
            static::$timers[$address] = [];
        }

        static::removeTimer($address, $peer);

        static::$timers[$address][$peer] = $data;
    }


    public static function removeTimer($address, $peer)
    {
        if (isset(static::$timers[$address][$peer])) {
            \React\EventLoop\Loop::cancelTimer(PeerManager::$timers[$address][$peer]['timer']);
            unset(PeerManager::$timers[$address][$peer]);
        }
    }


    public static function addPeered($address, $peer)
    {
        if (!isset(static::$peereds[$address])) {
            static::$peereds[$address] = [];
        }

        if (!isset(static::$peereds[$address][$peer])) {
            static::$peereds[$address][$peer] = true;
        }
    }

    public static function hasPeered($address, $peer)
    {
        return isset(static::$peereds[$address][$peer]);
    }

    public static function removePeered($address, $peer)
    {
        if (isset(static::$peereds[$address][$peer])) {
            unset(static::$peereds[$address][$peer]);
            if (empty(static::$peereds[$address])) {
                unset(static::$peereds[$address]);
            }
        }
    }

    public static function localAddressIsPeerd($address)
    {
        if (isset(static::$peereds[$address]) && !empty(static::$peereds[$address])) {
            return true;
        }

        return false;
    }

    public static function print()
    {

        echo "======> current p2p connection address: " . implode(', ', static::getConnectionAddresses()) . PHP_EOL;

        foreach (static::$peereds as $address => $peereds) {
            echo "======> address: {$address} " . PHP_EOL;
            echo "        peereds: " . implode(',', array_keys($peereds)) . PHP_EOL;
        }
        
        echo "======> tcp peerings: ". implode(',', static::getTcpPeers('')) . PHP_EOL; 

        if (empty(static::$peereds)) {
            echo "======> no peer is connected" . PHP_EOL;
        }

    }


    public static function handleClientConnection($connection, $request, $uuid)
    {
        $uri = $request->getHeaderLine('Uri');

        $uris = array_values(array_filter(explode(',', $uri)));
        if (empty($uris)) {
            static::getLogger()->error("peer: " . $connection->getRemoteAddress() . " domain is empty", [
                'request' => Helper::toString($request),
                'protocol' => $connection->protocol ?? '',
            ]);
            echo $d = base64_decode($request->getHeaderLine('Data')) . PHP_EOL;

            if (strpos($d, "\r\n\r\n") !== false) {
                $r = Psr7\parse_response($d);
                var_dump(base64_decode($r->getHeaderLine('Data')));
            }

            $connection->write("HTTP/1.1 400 Bad Request\r\n\r\n");
            $connection->end();
            return;
        }
        static::getLogger()->notice('add peer', [
            'uri' => $uri,
            'protocol' => $connection->protocol ?? '',
        ]);
        foreach ($uris as $key1 => $uri) {
            // 域名
            if (strpos($uri, ':') === false) {
                if ($connection->protocol == 'p2p-udp') {
                    $uri = 'p2p-udp-' . $uri;
                } else if ($connection->protocol == 'p2p-tcp') {
                    $uri = 'p2p-tcp-' . $uri;
                }
                array_push($uris, $uri);
            }
            // ip 
            else {
                if ($connection->protocol == 'p2p-udp') {
                    $uri = 'udp://' . $uri;
                } else if ($connection->protocol == 'p2p-tcp') {
                    $uri = 'tcp://' . $uri;
                }
                array_push($uris, $uri);     
            }
        }
        foreach ($uris as $key => $_uri) {
            static::getLogger()->debug('add tunnel connection', [
                'uuid' => $uuid,
                'uri' => $_uri,
                'request' => Helper::toString($request)
            ]);

            if (!isset(ProxyManager::$remoteTunnelConnections[$_uri])) {
                ProxyManager::$remoteTunnelConnections[$_uri] = new \SplObjectStorage;
            }

            // if (ProxyManager::$remoteTunnelConnections[$_uri]->count()>= \Wpjscc\PTP\Config::getValue('common.max_tunnel_number', 5)) {
            //     static::getLogger()->error('remote tunnel connection count is more than 5', [
            //         'uri' => $_uri,
            //         'uuid' => $uuid,
            //         'request' => Helper::toString($request)
            //     ]);
            //     $connection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
            //     $connection->end();
            //     return;
            // }

            ProxyManager::$remoteTunnelConnections[$_uri]->attach($connection, [
                'Single-Tunnel' => $request->getHeaderLine('Single-Tunnel'),
                'Local-Host' => $request->getHeaderLine('Local-Host'),
                'Local-Protocol' => $request->getHeaderLine('Local-Protocol'),
                'Local-Replace-Host' => $request->getHeaderLine('Local-Replace-Host'),
                'Uuid' => $uuid,
            ]);
            $connection->on('close', function () use ($_uri, $connection, $request, $uuid) {
                ProxyManager::$remoteTunnelConnections[$_uri]->detach($connection);
                static::getLogger()->notice('remove tunnel connection', [
                    'uri' => $request->getHeaderLine('Uri'),
                    'uuid' => $uuid,
                ]);
                if (ProxyManager::$remoteTunnelConnections[$_uri]->count() == 0) {
                    unset(ProxyManager::$remoteTunnelConnections[$_uri]);
                    // p2p 是下方的带宽
                    unset(ProxyManager::$uriToInfo[$_uri]);
                }
            });

            // 设置p2p带宽默20M 最大100M
            ProxyManager::$uriToInfo[$uri]['bandwidth_limit']['max_bandwidth'] = 100;
            ProxyManager::$uriToInfo[$uri]['bandwidth_limit']['bandwidth'] = 20;
        }

        echo Helper::toString($request) . PHP_EOL;
        // throw new \Exception("not support uri: {$uri}");
    }

    // 在虚拟连接上创建的动态通道
    public static function handleOverVirtualConnection($singleConnection, $response, $connection, $uuid)
    {
        $request = $response;
        static::getLogger()->debug('add dynamic connection by p2p single tunnel', [
            'uri' => $response->getHeaderLine('Uri'),
            'uuid' => $response->getHeaderLine('Uuid'),
            'remote_address' => $singleConnection->getRemoteAddress(),
        ]);
        $request = $response;
        $uri = $request->getHeaderLine('Uri');


        $uris = array_values(array_filter(explode(',', $uri)));

        $isExist = false;

        foreach ($uris as $key1 => $_uri) {
            if (strpos($_uri, ':') === false) {
                if ($connection->protocol == 'p2p-udp') {
                    $_uri = 'p2p-udp-' . $_uri;
                } else if ($connection->protocol == 'p2p-tcp') {
                    $_uri = 'p2p-tcp-' . $_uri;
                }
                array_push($uris, $_uri);
            } 
            // ip 
            else {
                if ($connection->protocol == 'p2p-udp') {
                    $uri = 'udp://' . $uri;
                } else if ($connection->protocol == 'p2p-tcp') {
                    $uri = 'tcp://' . $uri;
                }
                array_push($uris, $uri);     
            }
        }

        foreach ($uris as $uri) {
            // if (strpos($uri, ':') === false) {
            //     if ($connection->protocol == 'p2p-udp') {
            //         $uri = 'p2p-udp-'.$uri;
            //     } else if ($connection->protocol == 'p2p-tcp') {
            //         $uri = 'p2p-tcp-'.$uri;
            //     }
            // }
            if (isset(ProxyManager::$remoteDynamicConnections[$uri]) && ProxyManager::$remoteDynamicConnections[$uri]->count() > 0) {
                static::getLogger()->debug('add dynamic connection by p2p single tunnel', [
                    'uri' => $request->getHeaderLine('Uri'),
                    'uuid' => $uuid,
                    'remote_address' => $singleConnection->getRemoteAddress(),
                ]);
                ProxyManager::$remoteDynamicConnections[$uri]->rewind();
                $deferred = ProxyManager::$remoteDynamicConnections[$uri]->current();
                ProxyManager::$remoteDynamicConnections[$uri]->detach($deferred);
                static::getLogger()->debug('deferred dynamic connection p2p single-tunnel', [
                    'uri' => $request->getHeaderLine('Uri'),
                    'uuid' => $uuid,
                    'remote_address' => $singleConnection->getRemoteAddress(),
                ]);
                $singleConnection->tunnelConnection = $connection;
                $deferred->resolve($singleConnection);
                $isExist = true;
                break;
            }
        }

        if (!$isExist) {
            static::getLogger()->warning('no dynamic connection by p2p single tunnel', [
                'uri' => $request->getHeaderLine('Uri'),
                'uuid' => $uuid,
                'remote_address' => $connection->getRemoteAddress(),
            ]);
            $singleConnection->write("HTTP/1.1 205 Not Support Created\r\n\r\n");
            $singleConnection->end();
        }
    }

    public static function addConnection($address, $peer, $connection)
    {

        static::$connections[$address][$peer]['connection'] = $connection;
    }

    public static function getConnection($address, $peer)
    {
        return static::$connections[$address][$peer]['connection'] ?? null;
    }

    // remove 
    public static function removeConnection($address, $peer)
    {

        if (strpos($address, '://') === false) {
            static::removeLocalAddrToRemoteAddrByRemoteAddr($address);
        }

        $connection = static::getConnection($address, $peer);
        if ($connection) {
            $connection->close();
        }
        $virtualConnection = static::getVirtualConnection($address, $peer);
        if ($virtualConnection) {
            $virtualConnection->close();
        }
        unset(static::$connections[$address][$peer]);
        if (empty(static::$connections[$address])) {
            unset(static::$connections[$address]);
        }
    }

    public static function removeAddressConnection($address)
    {
        if (isset(static::$connections[$address])) {
            foreach (static::$connections[$address] as $peer => $value) {
                static::removeConnection($address, $peer);
            }
        }
    }

    public static function getConnectionAddresses()
    {
        return array_keys(static::$connections);
    }

    public static function addVirtualConnection($address, $peer, $connection)
    {
        static::$connections[$address][$peer]['virtual_connection'] = $connection;
    }

    public static function getVirtualConnection($address, $peer)
    {
        return static::$connections[$address][$peer]['virtual_connection'] ?? null;
    }


    public static function addLocalAddrToRemoteAddr($localAddr, $remoteAddr, $isPeer = false)
    {
        if ($isPeer) {
            static::$peerAddrToRemoteAddr[$localAddr] = $remoteAddr;
        } else {
            static::$localAddrToRemoteAddr[$localAddr] = $remoteAddr;
        }
    }

    public static function getRemoteAddrByLocalAddr($localAddr, $isPeer = false)
    {
        if ($isPeer) {
            return static::$peerAddrToRemoteAddr[$localAddr] ?? null;
        }
        return static::$localAddrToRemoteAddr[$localAddr] ?? null;
    }

    public static function removeLocalAddrToRemoteAddr($localAddr, $isPeer = false)
    {
        if ($isPeer) {
            unset(static::$peerAddrToRemoteAddr[$localAddr]);
        } else {
            unset(static::$localAddrToRemoteAddr[$localAddr]);
        }
    }

    public static function removeLocalAddrToRemoteAddrByRemoteAddr($remoteAddr, $isPeer = false)
    {
        if ($isPeer) {
            unset(static::$peerAddrToRemoteAddr[array_search($remoteAddr, static::$peerAddrToRemoteAddr)]);
        } else {
            unset(static::$localAddrToRemoteAddr[array_search($remoteAddr, static::$localAddrToRemoteAddr)]);
        }
    }

    public static function getAddrs($isPeer = false)
    {
        if ($isPeer) {
            return array_unique(array_values(array_merge(array_keys(static::$peerAddrToRemoteAddr), array_values(static::$peerAddrToRemoteAddr))));
        } else {
            return array_unique(array_values(array_merge(array_keys(static::$localAddrToRemoteAddr), array_values(static::$localAddrToRemoteAddr))));
        }
    }

    public static function getTcpPeeredAddrs()
    {
        $peered = [];
        foreach (static::$connections as $address => $value) {
            if (strpos($address, 'tcp://') === 0) {
                $peered = array_merge($peered, array_keys($value));
            }
        }
        return array_unique($peered);  
    }
}
