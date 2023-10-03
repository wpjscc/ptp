<?php

namespace Wpjscc\Penetration\Tunnel\Client\Tunnel;

use React\Socket\ConnectorInterface;

use React\Promise\Deferred;
use Evenement\EventEmitter;
use Darsyn\IP\Exception;
use Darsyn\IP\Version\IPv4;
use Ramsey\Uuid\Nonstandard\Uuid;
use Wpjscc\Penetration\P2p\Client\PeerManager;
use Wpjscc\Penetration\P2p\ConnectionManager;
use Wpjscc\Penetration\Utils\PingPong;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\UdpTunnel;
use Wpjscc\Penetration\Utils\ParseBuffer;
use React\Stream\ThroughStream;
use Wpjscc\Penetration\CompositeConnectionStream;
use Wpjscc\Penetration\Connection;
use Wpjscc\Penetration\Helper;

class P2pTunnel extends EventEmitter implements ConnectorInterface, \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    protected $config;
    protected $uri;
    protected $server;

    protected $header;

    protected $currentAddress = '';
    protected $localAddress = '';

    public function __construct(&$config = [])
    {
        $this->config = &$config;

        // 发送给其他客户端的
        $header = [
            'Host: ' . $config['server_host'],
            'User-Agent: ReactPHP',
            'Tunnel: 1',
            'Authorization: ' . ($config['token'] ?? ''),
            'Local-Host: ' . $config['local_host'] . ':' . $config['local_port'],
            'Domain: ' . $config['domain'],
            'Uri: ' . $config['domain'],
            "\r\n"
            // 'Local-Tunnel-Address: ' . $connection->getLocalAddress(),
        ];
        $this->header = implode("\r\n", $header);
        $this->header = implode("\r\n", $header);
    }



    public function connect($uri)
    {
        // $deferred = new Deferred();

        $this->uri = $uri;
        $this->_connect($uri)->then(function ($tunnel) use ($uri) {
            // $deferred->resolve($this);
            $tunnel->on('connection', function ($connection, $address, $server) use ($uri) {
                $this->server = $server;
                
                // ConnectionManager::$connections[$address]['connection'] = $connection;
                PeerManager::addConnection($this->currentAddress, $address, $connection);

                $parseBuffer = new ParseBuffer();
                $parseBuffer->setAddress($address);
                $parseBuffer->on('response', [$this, 'handleResponse']);

                $connection->on('data', function ($data) use ($connection, $parseBuffer, $uri) {
                    // var_dump('p2pTunnelData', $data);
                    $parseBuffer->handleBuffer($data);
                });

                echo ('connection:' . $address."\n");
                PingPong::pingPong($connection, $address, $this->header);

                $connection->on('close', function () use ($address, $uri) {

                    // if (isset(PeerManager::$timers[$address])) {
                    //     echo "close and cancel timer $address" . PHP_EOL;
                    //     \React\EventLoop\Loop::cancelTimer(PeerManager::$timers[$address]['timer']);
                    //     unset(PeerManager::$timers[$address]);
                    // }
                    PeerManager::removeTimer($this->currentAddress, $address);

                    // if (in_array($address, PeerManager::$peers)) {
                    //     echo "close remove peers $address" . PHP_EOL;
                    //     PeerManager::$peers = array_values(array_diff(PeerManager::$peers, [$address]));
                    // }
                    PeerManager::removePeer($this->currentAddress, $address);


                    // if (isset(PeerManager::$peereds[$address])) {
                    //     echo "close remove peereds $address" . PHP_EOL;
                    //     unset(PeerManager::$peereds[$address]);
                    // }

                    PeerManager::removePeered($this->currentAddress, $address);


                    if ($address == $this->getServerIpAndPort()) {
                        echo "close retry after 3 seconds" . PHP_EOL;
                        \React\EventLoop\Loop::addTimer(3, function () use ($uri) {
                            $this->connect($uri);
                        });
                    }
                });
            });
        }, function ($e) use ($uri) {
            echo 'error1: ' . $e->getMessage() . PHP_EOL;
            echo 'retry after 3 seconds' . PHP_EOL;
            \React\EventLoop\Loop::addTimer(3, function () use ($uri) {
                $this->connect($uri);
            });
            return $e;
        })->otherwise(function ($e) use ($uri) {
            echo 'error2: ' . $e->getMessage() . PHP_EOL;
            echo 'retry after 3 seconds' . PHP_EOL;
            \React\EventLoop\Loop::addTimer(3, function () use ($uri) {
                $this->connect($uri);
            });
            return $e;
        });
        // $deferred->promise();
        return $this;
    }

    public function _connect($uri)
    {

        $deferred = new Deferred();


        (new \React\Datagram\Factory())->createClient($uri)->then(function (\React\Datagram\Socket $client) use ($uri, $deferred) {
            echo 'create client: ' . $uri . PHP_EOL;
            $ipRanges = [];
            foreach ($this->getIpRange() as $ipRane) {
                $ipRanges[] = "IP-Range: " . $ipRane;
            }

            $headers = [
                'GET /client HTTP/1.1',
                'Host: ' . $this->config['server_host'],
                'User-Agent: ReactPHP',
                'Tunnel: 1',
                'Authorization: ' . ($this->config['token'] ?? ''),
                'Local-Host: ' . $this->config['local_host'] . ':' . $this->config['local_port'],
                'Domain: ' . $this->config['domain'],
                'Single-Tunnel: ' . ($this->config['single_tunnel'] ?? 0),
                'Is-P2p: 1',
                // 'Local-Tunnel-Address: ' . $connection->getLocalAddress(),
            ];

            $request = implode("\r\n", $headers) . "\r\n\r\n";
            $client->send($request);
            
            
            // 给服务端发送本地地址和ip范围
            $client->send(implode("\r\n", [
                "HTTP/1.1 410 OK",
                "Local-Address: " . $client->getLocalAddress(),
                ...$ipRanges,
                "\r\n"
            ]));

            \React\Promise\Timer\timeout($deferred->promise(), 3)->then(null, function ($e) use ($deferred) {
                $deferred->reject($e);
            });

            // PeerManager::$localAddress = $client->getLocalAddress();
            $this->localAddress = $client->getLocalAddress();

            $parseBuffer = new ParseBuffer();
            $parseBuffer->on('response', function ($response) use ($deferred, $client, $ipRanges) {

                if ($response->getStatusCode() == 200) {
                    $uuid = $response->getHeaderLine('Uuid');
                    $this->config['uuid'] = $uuid;
                    
                    static::getLogger()->notice("P2pTunnel::".__FUNCTION__." 200", [
                        'class' => __CLASS__,
                        'response' => Helper::toString($response)
                    ]);
                }
                // 服务端回复客户端的地址
                else if ($response->getStatusCode() === 411) {
                    if (!isset($this->config['uuid'])) {
                        $this->config['uuid'] = Uuid::uuid4()->toString();
                    }
                    echo 'receive server address: ' . $response->getHeaderLine('Address') . PHP_EOL;
                    $address = $response->getHeaderLine('Address');
                    // PeerManager::$currentAddress = $address;
                    $this->currentAddress = $address;
                    // 客户端关闭
                    $client->close();
                    // 本地服务端监听客户端打开的端口
                    $deferred->resolve(new UdpTunnel('0.0.0.0:' . explode(':', $this->localAddress)[1], null, function ($server) {
                        // $client->send(implode("\r\n", [
                        //     "HTTP/1.1 410 OK",
                        //     "Local-Address: " . $client->getLocalAddress(),
                        //     ...$ipRanges,
                        //     "\r\n"
                        // ]));
                        // 给服务端回复可以广播地址了
                        $server->send("HTTP/1.1 413 OK\r\n\r\n", PeerManager::$serverAddress);
                    }));
                }
            });

            $client->on('message', function ($message, $serverAddress, $client) use ($parseBuffer) {
                PeerManager::$serverAddress = $serverAddress;
                $parseBuffer->handleBuffer($message);
            });
        }, function ($e) use ($deferred) {
            $deferred->reject($e);
            return $e;
        })->otherwise(function ($e) use ($deferred) {
            $deferred->reject($e);
            return $e;
        });




        return $deferred->promise();
    }


    protected function handleResponse($response, $parseBuffer)
    {
        $address = $parseBuffer->getAddress();
        // 收到服务端的广播地址
        if ($response->getStatusCode() === 413) {
            $addresses = array_values(array_filter([$response->getHeaderLine('Address')]));

            if (empty($addresses)) {
                $addresses = $response->getHeader('Addresses');
            }
            static::getLogger()->error("P2pTunnel::".__FUNCTION__." addresses", [
                'class' => __CLASS__,
                'addresses' => $addresses,
            ]);

            if (!empty($addresses)) {
                $addresses = array_map(function ($address) {
                    return strpos($address, '://') === false ? $address : explode('://', $address)[1];
                }, $addresses);

                $addresses = array_diff($addresses, PeerManager::getPeers($this->currentAddress), [
                    $this->currentAddress,
                    $this->localAddress
                ]);

                if (!empty($addresses)) {
                    // PeerManager::$peers = array_values(array_unique(array_merge($addresses, PeerManager::$peers)));
                    $peers = PeerManager::addPeer($this->currentAddress, $addresses);
                    foreach ($peers as $k => $peer) {

                        // if (isset(PeerManager::$timers[$peer])) {
                        //     \React\EventLoop\Loop::cancelTimer(PeerManager::$timers[$peer]['timer']);
                        //     unset(PeerManager::$timers[$peer]);
                        // }

                        PeerManager::removeTimer($this->currentAddress, $peer);

                       

                        // if (isset(PeerManager::$peereds[$peer])) {
                        //     echo "broadcast_address but $peer had peereds" . PHP_EOL;
                        //     // remove peers 
                        //     PeerManager::$peers = array_values(array_diff(PeerManager::$peers, [$peer]));
                        //     continue;
                        // }

                        if (PeerManager::hasPeered($this->currentAddress, $peer)) {
                            echo "broadcast_address but $peer had peered" . PHP_EOL;
                            // remove peers 
                            PeerManager::removePeer($this->currentAddress, $peer);
                            continue;
                        }

                        // 是否在有效范围内
                        $peerIp = explode(':', $peer)[0];
                        try {
                            $ip = IPv4::factory($peerIp);

                            $ipRange = $this->getIpRange();
                            $isInIpRange = false;
                            if (!empty($ipRange)) {
                                foreach ($ipRange as $range) {
                                    $range = explode('/', $range);
                                    $rangeIp = IPv4::factory($range[0]);
                                    $rangeCidr = $range[1] ?? 32;
                                    if ($ip->inRange($rangeIp, $rangeCidr)) {
                                        $isInIpRange = true;
                                        break;
                                    }
                                }
                            } else {
                                $isInIpRange = true;
                            }
                            if (!$isInIpRange) {
                                echo "broadcast_address but $peer not in ip range" . PHP_EOL;
                                // PeerManager::$peers = array_values(array_diff(PeerManager::$peers, [$peer]));
                                PeerManager::removePeer($this->currentAddress, $peer);
                                continue;
                            }
                        } catch (Exception\InvalidIpAddressException $e) {
                            echo ("The $peerIp  address supplied is invalid!");
                            // PeerManager::$peers = array_values(array_diff(PeerManager::$peers, [$peer]));
                            PeerManager::removePeer($this->currentAddress, $peer);
                            continue;
                        }
                       

                        // 开始打孔
                        // PeerManager::$timers[$peer] = [
                        //     'active' => time(),
                        //     'timer' => \React\EventLoop\Loop::addPeriodicTimer(0.5, function () use ($peer) {
                        //         echo 'send punch to ' . $peer . PHP_EOL . PHP_EOL;
                        //         $this->server->send("HTTP/1.1 414 punch \r\n".$this->header, $peer);
                        //     })
                        // ];

                        PeerManager::addTimer($this->currentAddress, $peer, [
                            'active' => time(),
                            'timer' => \React\EventLoop\Loop::addPeriodicTimer(0.5, function () use ($peer) {
                                echo 'send punch to ' . $peer . PHP_EOL . PHP_EOL;
                                $this->server->send("HTTP/1.1 414 punch \r\n".$this->header, $peer);
                            })
                        ]);

                        \React\EventLoop\Loop::addTimer(1, function () use ($peer) {
                            // 一秒后如果还没有打孔成功就取消
                            // if (isset(PeerManager::$timers[$peer])) {
                            //     echo "punch fail and cancel timer $peer" . PHP_EOL;
                            //     \React\EventLoop\Loop::cancelTimer(PeerManager::$timers[$peer]['timer']);
                            //     unset(PeerManager::$timers[$peer]);
                            // }
                            PeerManager::removeTimer($this->currentAddress, $peer);

                            // 取消perrs
                            // if (in_array($peer, PeerManager::$peers)) {
                            //     echo "punch fail and remove peers $peer" . PHP_EOL;
                            //     PeerManager::$peers = array_values(array_diff(PeerManager::$peers, [$peer]));
                            // }

                            PeerManager::removePeer($this->currentAddress, $peer);

                        });
                    }
                }
            }
        }
        // 收到 打孔
        else if ($response->getStatusCode() === 414) {
            // 回复 punched
            $this->server->send("HTTP/1.1 415 punched\r\n".$this->header, $address);
        }
        // 收到 punched  连上对方了 
        else if ($response->getStatusCode() === 415) {
            // 避免多次连接
            // if (!isset(PeerManager::$peereds[$address])) {
            if (!PeerManager::hasPeered($this->currentAddress, $address)) {

                // 取消定时器
                // if (isset(PeerManager::$timers[$address])) {
                //     echo "punched success and cancel timer $address" . PHP_EOL;
                //     \React\EventLoop\Loop::cancelTimer(PeerManager::$timers[$address]['timer']);
                //     unset(PeerManager::$timers[$address]);
                // }
                PeerManager::removeTimer($this->currentAddress, $address);

                // 取消perrs
                // if (in_array($address, PeerManager::$peers)) {
                //     echo "punched success and remove peers $address" . PHP_EOL;
                //     PeerManager::$peers = array_values(array_diff(PeerManager::$peers, [$address]));
                // }
                PeerManager::removePeer($this->currentAddress, $address);

                // 记录已经连上的
                // PeerManager::$peereds[$address] = true;

                PeerManager::addPeered($this->currentAddress, $address);
               
                // $this->emit('connection', [
                //     ConnectionManager::$connections[$address]['connection'],
                //     $address,
                //     $this->server
                // ]);
                $this->getVirtualConnection($response, $address);
            } else {
                echo "punched success already peered $address" . PHP_EOL;
            }
        }
        // 收到远端数据了
        else if ($response->getStatusCode() === 416) {
            $virtualConnection = $this->getVirtualConnection($response, $address);
            $data = $response->getHeaderLine('Data');
            $data = base64_decode($data);
            $virtualConnection->emit('data', [$data]);
        }
        // else if ($response->getStatusCode() === 300) {
        //     $this->server->send("HTTP/1.1 301 OK\r\n".$this->header, $address);
        // } 
        // 收到远端的pong
        else if ($response->getStatusCode() === 301) {
            if ($address != $this->getServerIpAndPort()) {
                // 一端能连接对方，但对方连接不到自己，这种情况下，能ping通，就可以连接上
                // if (!in_array($address, array_keys(PeerManager::$peereds))) {
                if (!PeerManager::hasPeered($this->currentAddress, $address)) {
                    // 结构
                    //                           NAT B 192.168.0.1
                    //                         
                    //                                |
                    //                                |
                    //         +----------------------+----------------------+
                    //         |                                             |
                    //       NAT A                                           |
                    // 192.168.1.1 /192.168.0.101                                 |
                    //         |                                             |
                    //         |                                             |
                    //      Client A                                      Client B
                    //   192.168.1.9                                  192.168.0.107

                    // Client A ->  NAT A -> NAT B -> Client B (是通的)
                    // Client B ->  NAT B -> NAT A -> Client A (是不通的，除非Client A先发起连接，这样Client B就能连上Client A了)
                    // Client A ----send punch---> Client B (发送不过去，不在一个网段)
                    // 这里在B上处理  $address 是 192.168.0.101
                    echo "pong success but not peered address $address" . PHP_EOL;
                    echo "add peereds $address" . PHP_EOL;
                    // PeerManager::$peereds[$address] = true;
                    PeerManager::addPeered($this->currentAddress, $address);
                    $this->getVirtualConnection($response, $address);

                }
            }
        }
        else {
            echo "ignore other response code" . PHP_EOL;
        }

    }

    public function getIpRange()
    {
        return $this->config['ip_range'] ?? [];
    }

    protected function getVirtualConnection($response, $address)
    {
        if (empty(PeerManager::getVirtualConnection($this->currentAddress, $address))) {
            // $connection = ConnectionManager::$connections[$address]['connection'];
            $connection = PeerManager::getConnection($this->currentAddress, $address);
 
            $read = new ThroughStream;
            $write = new ThroughStream;

            $write->on('data', function ($data) use ($connection) {
                $data = base64_encode($data);
                $connection->write("HTTP/1.1 416 OK\r\nData: {$data}\r\n\r\n");
            });

            $virtualConnection = new CompositeConnectionStream($read, $write, new Connection(
                $this->server->getLocalAddress(),
                $address
            ), 'p2p_udp');

            $connection->on('close', function () use ($virtualConnection) {
                $virtualConnection->close();
            });

            $virtualConnection->on('close', function () use ($connection, $address) {
                $connection->close();
            });

            // ConnectionManager::$connections[$address]['virtual_connection'] = $virtualConnection;

            PeerManager::addVirtualConnection($this->currentAddress, $address, $virtualConnection);
           

            if ($address != $this->getServerIpAndPort()) {
                static::getLogger()->debug("P2pTunnel::".__FUNCTION__, [
                    'class' => __CLASS__,
                    'address' => $address,
                    'server_ip_and_port' => $this->getServerIpAndPort(),
                ]);
                $this->emit('connection', [$virtualConnection, $response, $address]);
                // 底层连接已经ping pong了，这里不需要再ping pong了
                // PingPong::pingPong($virtualConnection, $address);
            }
        }
        return PeerManager::getVirtualConnection($this->currentAddress, $address);
    }

    public function getServerIpAndPort()
    {
        $ip = '';
        if (strpos($this->uri, '://') !== false) {
            $ip = explode('://', $this->uri)[1];
        } else {
            $ip = $this->uri;
        }
        return $ip;
    }
    
}
