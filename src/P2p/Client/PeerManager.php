<?php


namespace Wpjscc\Penetration\P2p\Client;

use Wpjscc\Penetration\Proxy\ProxyManager;
use Wpjscc\Penetration\Helper;
use RingCentral\Psr7;

class PeerManager implements \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    protected static $peers = [];
    protected static $peereds = [];
    // protected static $tcpPeereds = [];
    protected static $timers = [];

    protected static $connections = [];
    protected static $localAddrToRemoteAddr = [];


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

    // public static function addTcpPeered($address, $peer)
    // {
    //     if (!isset(static::$tcpPeereds[$address])) {
    //         static::$tcpPeereds[$address] = [];
    //     }

    //     if (!isset(static::$tcpPeereds[$address][$peer])) {
    //         static::$tcpPeereds[$address][$peer] = true;
    //     }
    // }

    // public static function hasTcpPeered($address, $peer)
    // {
    //     return isset(static::$tcpPeereds[$address][$peer]);
    // }

    // public static function removeTcpPeered($address, $peer)
    // {
    //     if (isset(static::$tcpPeereds[$address][$peer])) {
    //         unset(static::$tcpPeereds[$address][$peer]);
    //     }
    // }

    public static function print()
    {

        echo "====> current p2p connection address: " . implode(', ', static::getConnectionAddresses()) . PHP_EOL;

        foreach (static::$peereds as $address => $peereds) {
            echo "====> address: {$address} " . PHP_EOL;
            echo "      peereds: " . implode(',', array_keys($peereds)) . PHP_EOL;
        }

        if (empty(static::$peereds)) {
            echo "====> no peer is connected" . PHP_EOL;
        }

        // foreach (static::$tcpPeereds as $address => $peereds) {
        //     echo "      tcp-peereds: " . implode(',', array_keys($peereds)) . PHP_EOL;
        // }

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
        static::getLogger()->debug('add peer', [
            'uri' => $uri,
            'protocol' => $connection->protocol ?? '',
        ]);
        foreach ($uris as $key1 => $uri) {
            if (strpos($uri, ':') === false) {
                if ($connection->protocol == 'p2p-udp') {
                    $uri = 'p2p-udp-' . $uri;
                } else if ($connection->protocol == 'p2p-tcp') {
                    $uri = 'p2p-tcp-' . $uri;
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

            // todo 最大数量限制
            ProxyManager::$remoteTunnelConnections[$_uri]->attach($connection, [
                'Single-Tunnel' => $request->getHeaderLine('Single-Tunnel'),
                'Local-Host' => $request->getHeaderLine('Local-Host'),
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
                    unset(ProxyManager::$uriToToken[$_uri]);
                }
            });
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
            echo ("no dynamic connection by single tunnel" . $singleConnection->getRemoteAddress() . "\n");
            static::getLogger()->debug('no dynamic connection by p2p single tunnel', [
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


    public static function addLocalAddrToRemoteAddr($localAddr, $remoteAddr)
    {
        static::$localAddrToRemoteAddr[$localAddr] = $remoteAddr;
    }

    public static function getLocalAddrToRemoteAddr($localAddr)
    {
        return static::$localAddrToRemoteAddr[$localAddr] ?? null;
    }

    public static function removeLocalAddrToRemoteAddr($localAddr)
    {
        unset(static::$localAddrToRemoteAddr[$localAddr]);
    }

    public static function removeLocalAddrToRemoteAddrByRemoteAddr($remoteAddr)
    {
        unset(static::$localAddrToRemoteAddr[array_search($remoteAddr, static::$localAddrToRemoteAddr)]);
    }

    public static function getAddrs()
    {
        return array_unique(array_values(array_merge(array_keys(static::$localAddrToRemoteAddr), array_values(static::$localAddrToRemoteAddr))));
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
