<?php

namespace Wpjscc\PTP\Tunnel\Client\Tunnel;

use React\Socket\ConnectorInterface;

use React\Promise\Deferred;
use Evenement\EventEmitter;
use Ramsey\Uuid\Nonstandard\Uuid;
use Wpjscc\PTP\P2p\Client\PeerManager;
use Wpjscc\PTP\Utils\PingPong;
use Wpjscc\PTP\Tunnel\Client\Tunnel\UdpTunnel as ClientUdpTunnel;
use Wpjscc\PTP\Tunnel\Server\Tunnel\UdpTunnel;
use Wpjscc\PTP\Utils\ParseBuffer;
use React\Stream\ThroughStream;
use Wpjscc\PTP\CompositeConnectionStream;
use Wpjscc\PTP\Connection;
use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Utils\Ip;

class P2pTunnel extends EventEmitter implements ConnectorInterface, \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    protected $key;
    protected $config;
    protected $uri;
    protected $server;

    protected $header;

    protected $serverAddress = '';

    protected $currentAddress = '';
    protected $localAddress = '';

    protected $currentTcpNumber = 0;

    protected $close;

    public function __construct(&$config = [], $key)
    {
        $this->key = $key;
        $this->config = &$config;
        if (empty(PeerManager::$uuid)) {
            PeerManager::$uuid = Uuid::uuid4()->toString();
        }

        // 发送给对端的信息，不包含token和user pwd
        $header = [
            'Host: ' . $config['tunnel_host'],
            'User-Agent: ReactPHP',
            'Tunnel: 1',
            'Authorization: ' . ($config['token'] ?? ''),
            'Local-Host: ' . $config['local_host'] . (($config['local_port'] ?? '') ? (':' . $config['local_port']) : ''),
            'Local-Protocol: ' . ($config['local_protocol']),
            'Local-Replace-Host: ' . ($config['local_replace_host'] ?? 0),
            'Domain: ' . $config['domain'],
            'Uri: ' . $config['domain'],
            "\r\n"
        ];
        $this->header = implode("\r\n", $header);
    }



    public function connect($uri)
    {
        $this->uri = $uri;
        $this->_connect($uri)->then(function ($tunnel) use ($uri) {
            $tunnel->on('connection', function ($connection, $address, $server) use ($uri) {
                $this->server = $server;

                PeerManager::addConnection($this->currentAddress, $address, $connection);

                $parseBuffer = new ParseBuffer();
                $parseBuffer->setLocalAddress($this->currentAddress);
                $parseBuffer->setRemoteAddress($address);
                $parseBuffer->on('response', [$this, 'handleResponse']);

                $connection->on('data', function ($data) use ($connection, $parseBuffer, $uri, $address) {
                    // fix bug $getVirtualConnection $connection is null 
                    PeerManager::addConnection($this->currentAddress, $address, $connection);
                    $parseBuffer->handleBuffer($data);
                });

                echo ('connection:' . $address . "\n");
                PingPong::pingPong($connection, $address, $this->header);

                $connection->on('close', function () use ($address, $uri) {

                    // 移除打孔定时器
                    PeerManager::removeTimer($this->currentAddress, $address);
                    // 移除打孔的peer
                    PeerManager::removePeer($this->currentAddress, $address);
                    // 移除已经打孔成功的
                    PeerManager::removePeered($this->currentAddress, $address);


                    if ($address == $this->serverAddress) {
                        echo "close retry after 3 seconds" . PHP_EOL;
                        $this->tryAgain($uri);
                    }
                });
            });
        }, function ($e) use ($uri) {
            static::getLogger()->error("P2pTunnel::" . __FUNCTION__ . " error1", [
                'class' => __CLASS__,
                'uri' => $uri,
                'msg' => "retry after 3 seconds",
                'error' => $e->getMessage(),
            ]);
            $this->tryAgain($uri);
            return $e;
        })->otherwise(function ($e) use ($uri) {
            static::getLogger()->error("P2pTunnel::" . __FUNCTION__ . " error2", [
                'class' => __CLASS__,
                'uri' => $uri,
                'msg' => "retry after 3 seconds",
                'error' => $e->getMessage(),
            ]);
            $this->tryAgain($uri);
            return $e;
        });
        return $this;
    }

    public function tryAgain($uri)
    {
        if (!$this->close) {
            \React\EventLoop\Loop::addTimer(3, function () use ($uri) {
                $this->connect($uri);
            });
        }

    }

    public function _connect($uri)
    {

        $deferred = new Deferred();


        (new ClientUdpTunnel(false))->connect($uri)->then(function ($connection) use ($uri, $deferred) {
            echo 'create client: ' . $uri . PHP_EOL;
            $this->serverAddress = $connection->getRemoteAddress();
            $this->localAddress = $connection->getLocalAddress();
            $connection->close();

            \React\EventLoop\Loop::addTimer(0.002, function () use ($uri, $deferred) {
                $_server = null;
                $_start = null;
                $udpTunnel = new UdpTunnel('0.0.0.0:' . explode(':', $this->localAddress)[1], null, function ($server, $tunnel, $start) use (&$_server, &$_start) {
                    // kcp 暂没必要支持
                    // $tunnel->supportKcp();
                    $_server = $server;
                    $_start = $start;
                });

                \React\Promise\Timer\timeout($deferred->promise(), 3)->then(null, function ($e) use ($deferred) {
                    $deferred->reject($e);
                });


                $udpTunnel->on('connection', $fn = function ($connection, $address, $server) use ($udpTunnel, $deferred, &$fn) {
                    if ($address == $this->serverAddress) {
                        // 发送给服务端的
                        $connection->write(implode("\r\n", [
                            'GET /client HTTP/1.1',
                            'Host: ' . $this->config['tunnel_host'],
                            'X-Is-Ptp: 1',
                            'User-Agent: ReactPHP',
                            'Tunnel: 1',
                            'Secret-Key: '. ($this->config['secret_key'] ?? ''),
                            'Authorization: ' . ($this->config['token'] ?? ''),
                            'Local-Host: ' . $this->config['local_host'] . (($this->config['local_port'] ?? '') ? (':' . $this->config['local_port']) : ''),
                            'Domain: ' . $this->config['domain'],
                            'Single-Tunnel: ' . ($this->config['single_tunnel'] ?? 0),
                            'Is-Private: ' . ($this->config['is_private'] ?? 1),
                            'Is-P2p: 1',
                            'Http-User: '. ($this->config['http_user'] ?? ''),
                            'Http-Pwd: '. ($this->config['http_pwd'] ?? ''),
                            "\r\n"
                        ]));


                        $fnc = null;
                        $parseBuffer = new ParseBuffer();

                        $parseBuffer->on('response', function ($response) use ($deferred, $connection, $udpTunnel, $server, $fn, &$fnc) {
                            if ($response->getStatusCode() == 200) {
                                $uuid = $response->getHeaderLine('Uuid');
                                $this->config['uuid'] = $uuid;

                                static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " 200", [
                                    'class' => __CLASS__,
                                    'response' => Helper::toString($response)
                                ]);

                                // 给服务端发送本地地址和ip范围
                                $connection->write(implode("\r\n", [
                                    "HTTP/1.1 410 OK",
                                    "Local-Address: " . $this->localAddress,
                                    'Is-Need-Local: ' . ($this->config['is_need_local'] ?? 0),
                                    'IP-Whitelist: ' . $this->getIpWhitelist(),
                                    'IP-Blacklist: ' . $this->getIpBlacklist(),
                                    'token: ' . ($this->config['token'] ?? ''),
                                    'Try-Tcp: ' . ($this->config['try_tcp'] ?? '0'),
                                    "Uuid: ". PeerManager::$uuid,
                                    "\r\n"
                                ]));

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
                                PeerManager::addLocalAddrToRemoteAddr($this->localAddress, $address);
                                $udpTunnel->removeListener('connection', $fn);
                                $connection->removeListener('data', $fnc);
                                $fn = null;
                                $connection->write("HTTP/1.1 413 OK\r\n\r\n");
                                $deferred->resolve($udpTunnel);
                                $udpTunnel->emit('connection', [$connection, $this->serverAddress, $server]);

                            } else {
                                static::getLogger()->warning("P2pTunnel: ".$response->getStatusCode(), [
                                    'class' => __CLASS__,
                                    'response' => Helper::toString($response)
                                ]);
                            }
                        });
                        $connection->on('data', $fnc = function ($message) use ($parseBuffer) {
                            $parseBuffer->handleBuffer($message);
                        });


                        // 这里开启后
                        //                           Server
                        //                         
                        //                                |
                        //                                |
                        //         +----------------------+----------------------+
                        //         |                                             |
                        //         |                                             |
                        //         |                                             |
                        //      Client A                                      Client B
                        // Client A 和 Client B 可通过服务端发送消息
                        // 这里不开启，由于和服务端的连接是 udp 容易丢失数据

                        // if ($this->config['single_tunnel'] ?? 0) {
                        //     $singleTunnel = (new SingleTunnel());
                        //     $singleTunnel->overConnection($connection);
                        //     $singleTunnel->on('connection', function ($connection, $response) {
                        //         $buffer = '';
                        //         ClientManager::handleLocalConnection($connection, $this->config, $buffer, $response);
                        //     });
                        // }
                       
                    }
                });

                $udpTunnel->createConnection('', $this->serverAddress, $_server, $_start);
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
        $localAddress = $parseBuffer->getLocalAddress();
        $remoteAddress = $parseBuffer->getRemoteAddress();
        // 收到服务端的广播地址
        if ($response->getStatusCode() === 413) {
            // 只接受服务端的广播地址
            if ($remoteAddress != $this->serverAddress) {
                return;
            }
            $addresses = array_values(array_filter([$response->getHeaderLine('Address')]));

            $remotePeerAddress = $response->getHeaderLine('Remote-Peer-Address');

            

            static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . ":". $response->getStatusCode(), [
                'class' => __CLASS__,
                'addresses' => $addresses,
                'current_address' => $this->currentAddress,
                'local_address' => $this->localAddress,
            ]);

            if (!empty($addresses)) {
                $addresses = array_map(function ($address) {
                    return strpos($address, '://') === false ? $address : explode('://', $address)[1];
                }, $addresses);

                $addresses = array_diff($addresses, PeerManager::getPeers($this->currentAddress), PeerManager::getAddrs());

                if (!empty($addresses)) {
                    // 说明此次推送的是内网地址
                    if ($remotePeerAddress) {
                        PeerManager::addLocalAddrToRemoteAddr($addresses[0], $remotePeerAddress, true);
                    }

                    $peers = PeerManager::addPeer($this->currentAddress, $addresses);
                    static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " peers", [
                        'class' => __CLASS__,
                        'peers' => $peers,
                        'current_address' => $this->currentAddress,
                        'local_address' => $this->localAddress,
                    ]);
                    foreach ($peers as $k => $peer) {
                        
                        // 取消定时器
                        PeerManager::removeTimer($this->currentAddress, $peer);

                        // 取消perrs
                        if (PeerManager::hasPeered($this->currentAddress, $peer) && PeerManager::hasPeered('tcp://'. $this->localAddress, 'tcp://'. $peer)) {
                            echo "broadcast_address but $peer had peered" . PHP_EOL;
                            // remove peers 
                            PeerManager::removePeer($this->currentAddress, $peer);
                            continue;
                        }


                        // 过滤ip白名单和黑名单
                        if (!Ip::addressInIpWhitelist($peer, $this->getIpWhitelist()) || Ip::addressInIpBlacklist($peer, $this->getIpBlacklist())) {
                            echo "broadcast_address but $peer not in ip whitelist or in ip blacklist" . PHP_EOL;
                            PeerManager::removePeer($this->currentAddress, $peer);
                            continue;
                        }

                        $this->server->send("HTTP/1.1 414 punch \r\n" . $this->header, $peer);

                        // if (($this->config['try_tcp'] ?? false ) && $response->getHeaderLine('Try-Tcp')) {
                        //     // 没被连上才可以连, 一个 tcp client 只能连一个 对端,(大概率是nat对tcp限制了)
                        //     if (!PeerManager::localAddressIsPeerd('tcp://'. $this->localAddress) && !in_array('tcp://'.$peer,PeerManager::getTcpPeeredAddrs())) {
                        //         static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " tcp peering", [
                        //             'class' => __CLASS__,
                        //             'peer' => $peer,
                        //             'current_address' => $this->currentAddress,
                        //             'local_address' => $this->localAddress,
                        //         ]);
                        //         $this->currentTcpNumber++;
                        //         static::punchTcpPeer($peer, 0 , $this->currentTcpNumber);
                        //     } else {
                        //     static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " tcp peered", [
                        //             'class' => __CLASS__,
                        //             'peer' => $peer,
                        //             'current_address' => $this->currentAddress,
                        //             'local_address' => $this->localAddress,
                        //         ]);
                        //     }
                        // }
                        
                        // 开始打孔
                        PeerManager::addTimer($this->currentAddress, $peer, [
                            'active' => time(),
                            'timer' => \React\EventLoop\Loop::addPeriodicTimer(0.4, function () use ($peer) {
                                static::getLogger()->debug("P2pTunnel 414 punch", [
                                    'class' => __CLASS__,
                                    'peer' => $peer,
                                    'current_address' => $this->currentAddress,
                                    'local_address' => $this->localAddress,
                                ]);
                                // 打孔 UDP
                                // udp server 同时给对方发送信息
                                // Udp Server Client A -------->>>>>>>>>>>>>>---<<<<<<<<<<<<----------- Client B---Udp Server
                                $this->server->send("HTTP/1.1 414 punch \r\n" . $this->header, $peer);
                            })
                        ]);

                        \React\EventLoop\Loop::addTimer(1, function () use ($peer) {
                            // 取消定时器
                            PeerManager::removeTimer($this->currentAddress, $peer);
                            // 取消perrs
                            PeerManager::removePeer($this->currentAddress, $peer);
                        });
                    }
                }
            }
        }
        // 收到 打孔
        else if ($response->getStatusCode() === 414) {
            // 回复 punched
            $this->server->send("HTTP/1.1 415 punched\r\n" . $this->header, $remoteAddress);
        }
        // 收到 punched  连上对方了 
        else if ($response->getStatusCode() === 415) {
            // udp 避免多次连接
            if (!PeerManager::hasPeered($this->currentAddress, $remoteAddress)) {
                static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . "415 punched", [
                    'class' => __CLASS__,
                    'address' => $remoteAddress,
                ]);

                // 取消定时器
                PeerManager::removeTimer($this->currentAddress, $remoteAddress);

                // 取消perrs
                PeerManager::removePeer($this->currentAddress, $remoteAddress);

                // 记录已经连上的
                PeerManager::addPeered($this->currentAddress, $remoteAddress);

                $this->getVirtualConnection($response, $localAddress, $remoteAddress);
            } else {
                echo "punched success already peered $remoteAddress" . PHP_EOL;
            }
        }
        // 收到远端数据了
        else if ($response->getStatusCode() === 416) {
            $virtualConnection = $this->getVirtualConnection($response, $localAddress, $remoteAddress);
            $data = $response->getHeaderLine('Data');
            if ($this->config['is_encrypt'] ?? false) {
                $data = Helper::decrypt($data, $this->config['encrypt_key'] ?? '', $this->config['encrypt_key'] ?? '');
            } else {
                $data = base64_decode($data);
            }
            if (!$virtualConnection) {
                static::getLogger()->error("P2pTunnel::" . __FUNCTION__ . " 416", [
                    'class' => __CLASS__,
                    'address' => $remoteAddress,
                    'data' => $data,
                ]);
            }

            // 消息的无序性 导致 416 先过来了，或者一端先连上对端了
            if ($virtualConnection) {
                $virtualConnection->emit('data', [$data]);
            }
        }
        // 注册服务的信息
        else if ($response->getStatusCode() === 417) {
            $data = $response->getHeaderLine('Data');
            $data = json_decode(base64_decode($data), true);

            $name = $data['name'] ?? '';
            $desc = $data['desc'] ?? '';
            static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " 417", [
                'class' => __CLASS__,
                'local_address' => $localAddress,
                'remote_address' => $remoteAddress,
                'name' => $name,
                'desc' => $desc,
            ]);
            // todo 注册的服务信息，比如在双方不知道的情况下，可以通过这个来知道对方的服务信息

            $this->getVirtualConnection($response, $localAddress, $remoteAddress);

        }
        // 收到远端的服务端的tcp打孔请求
        else if ($response->getStatusCode() === 418) {
            if ($remoteAddress === $this->serverAddress) {
                // 没被连上才可以连, 一个 tcp client 只能连一个 对端,(大概率是nat对tcp限制了)
                $peer = $response->getHeaderLine('Address');
                $bindAddress = $this->localAddress;
                // $currentAddress = $response->getHeaderLine('Current-Address');
                // if (Ip::isPrivateUse($currentAddress)) {
                //     $bindAddress = $currentAddress;
                // }

                if (!PeerManager::localAddressIsPeerd('tcp://'. $bindAddress) && !in_array('tcp://'.$peer, PeerManager::getTcpPeeredAddrs())) {
                    static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " tcp peering", [
                        'class' => __CLASS__,
                        'peer' => $peer,
                        'current_address' => $this->currentAddress,
                        'local_address' => $this->localAddress,
                        'biind_to' => $bindAddress,
                    ]);
                    
                    if (!PeerManager::hasTcpPeer($bindAddress, $peer)) {
                        $this->currentTcpNumber++;
                        PeerManager::addTcpPeer($bindAddress, $peer);
                        $remotePeerAddress = $response->getHeaderLine('Remote-Peer-Address');
                        static::punchTcpPeer($peer, 0 , $this->currentTcpNumber, $bindAddress, $remotePeerAddress);
                    }
                } else {
                    static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " tcp peered", [
                        'class' => __CLASS__,
                        'peer' => $peer,
                        'current_address' => $this->currentAddress,
                        'local_address' => $this->localAddress,
                    ]);
                }
            }
        }
        // 收到远端的pong
        else if ($response->getStatusCode() === 301) {
            if ($remoteAddress != $this->serverAddress) {

                // 一端能连接对方，但对方连接不到自己，这种情况下，能ping通，就可以连接上
                if (!PeerManager::hasPeered($localAddress, $remoteAddress)) {
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
                    echo "$localAddress \n";
                    echo "pong success but not peered address $remoteAddress" . PHP_EOL;
                    echo "add peereds $remoteAddress" . PHP_EOL;
                    PeerManager::addPeered($localAddress, $remoteAddress);
                    $this->getVirtualConnection($response, $localAddress, $remoteAddress);
                }
            }
        } else {
            echo "ignore other response code" . PHP_EOL;
        }
    }

    // 打孔tcp
    // tcp client 同时连接对方
    // tcp client Client A-------->>>>>>>>>>>>>>---<<<<<<<<<<<<-----------Client B tcp client

    protected function punchTcpPeer($peer, $times = 0, $currentNumber = 0, $bindAddress, $remotePeerAddress)
    {
        if ($currentNumber != $this->currentTcpNumber) {
            PeerManager::removeTcpPeer($bindAddress, $peer);
            \React\EventLoop\Loop::addTimer(10, function () use ($peer, $remotePeerAddress) {
                $ip = Ip::getIp($peer);
                $address = Ip::getIpAndPort($peer);
                $realAddress = $address;
                if (Ip::isPrivateUse($ip)) {
                    $address = PeerManager::getRemoteAddrByLocalAddr($peer, true)?: $address;
                }
                $this->server->send("HTTP/1.1 414 OK\r\nAddress:{$this->currentAddress}\r\nPeer: {$remotePeerAddress}\r\nReal-Peer: {$realAddress}\r\n\r\n", $this->serverAddress);
            });
            static::getLogger()->error("P2pTunnel::" . __FUNCTION__ . " currentNumber", [
                'class' => __CLASS__,
                "bindto" => $bindAddress,
                "local_address" => $this->localAddress,
                'peer' => $peer,
                'currentNumber' => $currentNumber,
                '$this->currentTcpNumber' => $this->currentTcpNumber,
            ]);
            return;
        }

        if ($times >= 10) {
            PeerManager::removeTcpPeer($bindAddress, $peer);
            \React\EventLoop\Loop::addTimer(10, function () use ($peer, $remotePeerAddress) {
                $ip = Ip::getIp($peer);
                $address = Ip::getIpAndPort($peer);
                $realAddress = $address;
                $this->server->send("HTTP/1.1 414 OK\r\nAddress:{$this->currentAddress}\r\nPeer: {$remotePeerAddress}\r\nReal-Peer: {$realAddress}\r\n\r\n", $this->serverAddress);
            });
            static::getLogger()->warning("P2pTunnel::" . __FUNCTION__ . " timeout", [
                'class' => __CLASS__,
                'peer' => $peer,
                'times' => $times,
            ]);
            return;
        }
        $times++;
        $remoteAddress = strpos($peer, '://') == false ? 'tcp://' . $peer : $peer;
        
        $localAddress = strpos($this->localAddress, '://') == false ? 'tcp://'.$this->localAddress : $this->localAddress;

         (new \React\Socket\Connector([
            'timeout' => 1,
            "tcp" => [
                "bindto" => $bindAddress
            ]
        ]))->connect($peer)->then(function ($connection) use ($peer, $bindAddress, $remotePeerAddress) {

            $localAddress = $connection->getLocalAddress();
            $remoteAddress = $connection->getRemoteAddress();


            $connection->protocol = 'p2p-tcp';
            $data = [
                'name' => $this->config['name'] ?? 'tcp',
                'desc' => $this->config['desc'] ?? 'tcp',
            ];

            $data = base64_encode(json_encode($data));

            $connection->write("HTTP/1.1 417 OK\r\nData: $data\r\nRemote-Address: {$this->currentAddress}\r\n" . $this->header);

            static::getLogger()->debug("P2pTunnel::punchTcpPeer->" . " connected", [
                'class' => __CLASS__,
                'local_address' => $localAddress,
                'remoter_address' => $remoteAddress,
            ]);
            
            // $address = $connection->getRemoteAddress();
            PeerManager::addPeered($localAddress, $remoteAddress);
            PeerManager::addConnection($localAddress, $remoteAddress, $connection);

            $parseBuffer = new ParseBuffer();
            $parseBuffer->setLocalAddress($localAddress);
            $parseBuffer->setRemoteAddress($remoteAddress);
            $parseBuffer->on('response', [$this, 'handleResponse']);

            $connection->on('data', function ($data) use ($connection, $parseBuffer, $localAddress, $remoteAddress) {
                // fix bug $getVirtualConnection $connection is null 
                echo 'tcp receive ' . $data . ' from ' . $connection->getRemoteAddress() . PHP_EOL;
                static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " tcp data", [
                    'class' => __CLASS__,
                    'localAddress' => $localAddress,
                    'remoteAddress' => $remoteAddress,
                ]);

                PeerManager::addConnection($localAddress, $remoteAddress, $connection);
                $parseBuffer->handleBuffer($data);
            });

            static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " tcp connection", [
                'class' => __CLASS__,
                'peer' => $peer,
                'address' => $remoteAddress
            ]);


            PingPong::pingPong($connection, $peer, "Remote-Address: {$this->currentAddress}\r\n".$this->header);
            $connection->on('close', function () use ($connection, $localAddress, $remoteAddress, $peer, $bindAddress) {
                echo 'close ' . $connection->getRemoteAddress() . PHP_EOL;
                PeerManager::removePeered($localAddress, $remoteAddress);
                PeerManager::removeConnection($localAddress, $remoteAddress);

                // 试着移除peer 的local 和远程对应关系
                $ipAndPort = explode('://', $remoteAddress)[0];
                PeerManager::removeLocalAddrToRemoteAddr($ipAndPort, true);

                // 移除配对的peer
                PeerManager::removeTcpPeer($bindAddress, $peer);

            });
        }, function ($e) use ($localAddress, $remoteAddress, $times, $currentNumber, $bindAddress, $remotePeerAddress) {
            static::getLogger()->error($e->getMessage(), [
                'class' => __CLASS__,
                "bindto" => $bindAddress,
                "local_address" => $this->localAddress,
                'currentNumber' => $currentNumber,
                'peer' => $remoteAddress,
                'times' => $times,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            if (!PeerManager::hasPeered($bindAddress,$remoteAddress)) {
                \React\EventLoop\Loop::addTimer(0.1, function () use ($remoteAddress, $times, $currentNumber, $bindAddress, $remotePeerAddress) {
                    self::punchTcpPeer($remoteAddress, $times, $currentNumber, $bindAddress, $remotePeerAddress);
                });
            } else {
                static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " hasTcpPeered", [
                    'class' => __CLASS__,
                    'peer' => $remoteAddress,
                    'time' => $times,
                ]);
            }

            return $e;
        })->otherwise(function ($e) use ($localAddress,$remoteAddress, $times, $currentNumber, $bindAddress, $remotePeerAddress) {
            static::getLogger()->error($e->getMessage().'-222', [
                'class' => __CLASS__,
                "bindto" => $this->localAddress,
                'currentNumber' => $currentNumber,
                'local_address' => $localAddress,
                'peer' => $remoteAddress,
                'times' => $times,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            if (!PeerManager::hasPeered($bindAddress, $remoteAddress)) {
                \React\EventLoop\Loop::addTimer(0.1, function () use ($remoteAddress, $times, $currentNumber, $bindAddress, $remotePeerAddress) {
                    self::punchTcpPeer($remoteAddress, $times, $currentNumber, $bindAddress, $remotePeerAddress);
                });
            }
            return $e;
        });
    }

    public function getIpWhitelist()
    {
        return $this->config['ip_whitelist'] ?? '';
    }

    public function getIpBlacklist()
    {
        return $this->config['ip_blacklist'] ?? '';
    }

    protected function getVirtualConnection($response, $localAddress,$address)
    {
        if (empty(PeerManager::getVirtualConnection($localAddress, $address))) {
            $connection = PeerManager::getConnection($localAddress, $address);
            static::getLogger()->debug("P2pTunnel::" . __FUNCTION__, [
                'class' => __CLASS__,
                'local_address' => $localAddress,
                'remote_address' => $address,
            ]);

            $read = new ThroughStream;
            $write = new ThroughStream;

            $write->on('data', function ($data) use ($connection) {
                if($this->config['is_encrypt'] ?? false){
                    $data = Helper::encrypt($data, $this->config['encrypt_key'] ?? '', $this->config['encrypt_key'] ?? '');
                } else {
                    $data = base64_encode($data);
                }
                $connection->write("HTTP/1.1 416 OK\r\nData: {$data}\r\n\r\n");
            });

            $virtualConnection = new CompositeConnectionStream($read, $write, new Connection(
                $localAddress,
                $address
            ), $connection->protocol == 'p2p-tcp' ? 'p2p-tcp' : 'p2p-udp');

            if ($connection) {
                $connection->on('close', function () use ($virtualConnection, $localAddress, $address) {
                    PeerManager::removeConnection($localAddress, $address);
                    $virtualConnection->close();
                });
            }
           

            $virtualConnection->on('close', function () use ($connection,$localAddress,$address) {
                PeerManager::removeConnection($localAddress, $address);
                $connection->close();
            });

            $data = [
                'name' => $this->config['name'] ?? '',
                'desc' => $this->config['desc'] ?? '',
            ];
            $data = base64_encode(json_encode($data));
            $connection->write("HTTP/1.1 417 OK\r\nData: $data\r\nRemote-Address: {$this->currentAddress}\r\n" . $this->header);

            PeerManager::addVirtualConnection($localAddress, $address, $virtualConnection);


            if ($address != $this->serverAddress) {
                static::getLogger()->notice("P2pTunnel::打孔成功", [
                    'class' => __CLASS__,
                    'local_address' => $localAddress,
                    'remote_adress' => $address,
                    'protocol' => $connection->protocol,
                    'response' => Helper::toString($response)
                ]);
                $this->emit('connection', [$virtualConnection, $response, $address]);
                // 底层连接已经ping pong了，这里不需要再ping pong了
                // PingPong::pingPong($virtualConnection, $address);
            }

            // 告诉服务端 可以开始tcp打孔了
            if (strpos($localAddress, 'tcp://') === false &&($this->config['try_tcp'] ?? false )) {
                $ip = explode(':', $address)[0];
                $realAddress = $address;

                // 运行在本地服务器
                if (Ip::isPrivateUse($this->currentAddress)) {
                        
                } else {
                    if (Ip::isPrivateUse($ip)) {
                        $address = PeerManager::getRemoteAddrByLocalAddr($address, true);
                        if (!$address) {
                            // 说明是在nat后面的,对方pong过来的活着417过来的
                            $address = $response->getHeaderLine('Remote-Address');
                            static::getLogger()->error("P2pTunnel::Remote-Address", [
                                'class' => __CLASS__,
                                'address' => $address,
                                'current_address' => $this->currentAddress,
                                'local_address' => $this->localAddress,
                                'response' => Helper::toString($response)
                            ]);
                        }
                        
                    }
                }
                
              
                $this->server->send("HTTP/1.1 414 OK\r\nAddress:{$this->currentAddress}\r\nPeer: {$address}\r\nReal-Peer: {$realAddress}\r\n\r\n", $this->serverAddress);
            }
        }
        return PeerManager::getVirtualConnection($localAddress, $address);
    }

    public function close()
    {
        $this->close = true;

        // udp peer remove
        PeerManager::removeAddressConnection($this->currentAddress);
        // tcp peer remove
        PeerManager::removeAddressConnection('tcp://'.$this->localAddress);

    }
}
