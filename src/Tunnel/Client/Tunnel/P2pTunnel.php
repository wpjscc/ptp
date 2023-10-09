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
use Wpjscc\Penetration\Tunnel\Client\Tunnel\UdpTunnel as ClientUdpTunnel;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\UdpTunnel;
use Wpjscc\Penetration\Utils\ParseBuffer;
use React\Stream\ThroughStream;
use Wpjscc\Penetration\CompositeConnectionStream;
use Wpjscc\Penetration\Connection;
use Wpjscc\Penetration\Helper;
use Wpjscc\Penetration\Utils\Ip;

class P2pTunnel extends EventEmitter implements ConnectorInterface, \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    protected $config;
    protected $uri;
    protected $server;

    protected $header;

    protected $serverAddress = '';

    protected $currentAddress = '';
    protected $localAddress = '';

    protected $currentTcpNumber = 0;

    public function __construct(&$config = [])
    {
        $this->config = &$config;

        // 发送给其他客户端的
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
            // 'Local-Tunnel-Address: ' . $connection->getLocalAddress(),
        ];
        var_dump($header);
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

                PeerManager::addConnection($this->currentAddress, $address, $connection);

                $parseBuffer = new ParseBuffer();
                $parseBuffer->setLocalAddress($this->currentAddress);
                $parseBuffer->setRemoteAddress($address);
                $parseBuffer->on('response', [$this, 'handleResponse']);

                $connection->on('data', function ($data) use ($connection, $parseBuffer, $uri, $address) {
                    // fix bug $getVirtualConnection $connection is null 
                    // var_dump('connection:p2pTunnelData', $data);
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


        (new ClientUdpTunnel(false))->connect($uri)->then(function ($connection) use ($uri, $deferred) {
            echo 'create client: ' . $uri . PHP_EOL;
            $this->serverAddress = $connection->getRemoteAddress();
            $this->localAddress = $connection->getLocalAddress();
            $connection->close();

            \React\EventLoop\Loop::addTimer(0.002, function () use ($uri, $deferred) {
                $_server = null;
                $_start = null;
                $udpTunnel = new UdpTunnel('0.0.0.0:' . explode(':', $this->localAddress)[1], null, function ($server, $tunnel, $start) use (&$_server, &$_start) {
                    // $tunnel->supportKcp();
                    $_server = $server;
                    $_start = $start;
                });

                \React\Promise\Timer\timeout($deferred->promise(), 3)->then(null, function ($e) use ($deferred) {
                    $deferred->reject($e);
                });


                $udpTunnel->on('connection', $fn = function ($connection, $address, $server) use ($udpTunnel, $deferred, &$fn) {
                    if ($address == $this->serverAddress) {
                        $headers = [
                            'GET /client HTTP/1.1',
                            'Host: ' . $this->config['tunnel_host'],
                            'User-Agent: ReactPHP',
                            'Tunnel: 1',
                            'Authorization: ' . ($this->config['token'] ?? ''),
                            'Local-Host: ' . $this->config['local_host'] . (($this->config['local_port'] ?? '') ? (':' . $this->config['local_port']) : ''),
                            'Domain: ' . $this->config['domain'],
                            // 'Single-Tunnel: ' . ($this->config['single_tunnel'] ?? 0),
                            'Is-P2p: 1',
                            // 'Local-Tunnel-Address: ' . $connection->getLocalAddress(),
                        ];

                        $request = implode("\r\n", $headers) . "\r\n\r\n";
                        $connection->write($request);


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

                                // 客户端关闭
                                // $connection->close();
                                // 本地服务端监听客户端打开的端口
                                // $deferred->resolve(new UdpTunnel('0.0.0.0:' . explode(':', $this->localAddress)[1], null, function ($server, $tunnel) {
                                //     // $client->send(implode("\r\n", [
                                //     //     "HTTP/1.1 410 OK",
                                //     //     "Local-Address: " . $client->getLocalAddress(),
                                //     //     ...$ipRanges,
                                //     //     "\r\n"
                                //     // ]));
                                //     // 给服务端回复可以广播地址了
                                //     $tunnel->setKcp(true);
                                //     $server->send("HTTP/1.1 413 OK\r\n\r\n", $this->serverAddress);
                                // }));
                            }
                        });
                        $connection->on('data', $fnc = function ($message) use ($parseBuffer) {
                            $parseBuffer->handleBuffer($message);
                        });
                       
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
            if ($remoteAddress != $this->serverAddress) {
                return;
            }
            $addresses = array_values(array_filter([$response->getHeaderLine('Address')]));

            if (empty($addresses)) {
                $addresses = $response->getHeader('Addresses');
            }
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
                    // PeerManager::$peers = array_values(array_unique(array_merge($addresses, PeerManager::$peers)));
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

                        // // 取消perrs[tcp]
                        // if () {
                        //     echo "broadcast_address but tcp $peer had peered" . PHP_EOL;
                        //     // remove peers 
                        //     PeerManager::removePeer($this->currentAddress, $peer);
                        //     continue;
                        // }



                        // 过滤ip白名单和黑名单
                        if (!Ip::addressInIpWhitelist($peer, $this->getIpWhitelist()) || Ip::addressInIpBlacklist($peer, $this->getIpBlacklist())) {
                            echo "broadcast_address but $peer not in ip whitelist or in ip blacklist" . PHP_EOL;
                            PeerManager::removePeer($this->currentAddress, $peer);
                            continue;
                        }

                        $this->server->send("HTTP/1.1 414 punch \r\n" . $this->header, $peer);

                        if (($this->config['try_tcp'] ?? false ) && $response->getHeaderLine('Try-Tcp')) {
                            // 没被连上才可以连, 一个 tcp client 只能连一个 对端,(大概率是nat对tcp限制了)
                            if (!PeerManager::localAddressIsPeerd('tcp://'. $this->localAddress) && !in_array('tcp://'.$peer,PeerManager::getTcpPeeredAddrs())) {
                                static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " tcp peering", [
                                    'class' => __CLASS__,
                                    'peer' => $peer,
                                    'current_address' => $this->currentAddress,
                                    'local_address' => $this->localAddress,
                                ]);
                                $this->currentTcpNumber++;
                                static::punchTcpPeer($peer, 0 , $this->currentTcpNumber);
                            } else {
                            static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " tcp peered", [
                                    'class' => __CLASS__,
                                    'peer' => $peer,
                                    'current_address' => $this->currentAddress,
                                    'local_address' => $this->localAddress,
                                ]);
                            }
                        }
                        
                       
                        // 开始打孔
                        PeerManager::addTimer($this->currentAddress, $peer, [
                            'active' => time(),
                            'timer' => \React\EventLoop\Loop::addPeriodicTimer(0.4, function () use ($peer) {
                                echo 'send punch to ' . $peer . PHP_EOL . PHP_EOL;
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
            // 避免多次连接
            // if (!isset(PeerManager::$peereds[$address])) {
            if (!PeerManager::hasPeered($this->currentAddress, $remoteAddress)) {
                static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . "415 punched", [
                    'class' => __CLASS__,
                    'address' => $remoteAddress,
                ]);
                // 取消定时器
                // if (isset(PeerManager::$timers[$address])) {
                //     echo "punched success and cancel timer $address" . PHP_EOL;
                //     \React\EventLoop\Loop::cancelTimer(PeerManager::$timers[$address]['timer']);
                //     unset(PeerManager::$timers[$address]);
                // }
                PeerManager::removeTimer($this->currentAddress, $remoteAddress);

                // 取消perrs
                // if (in_array($address, PeerManager::$peers)) {
                //     echo "punched success and remove peers $address" . PHP_EOL;
                //     PeerManager::$peers = array_values(array_diff(PeerManager::$peers, [$address]));
                // }
                PeerManager::removePeer($this->currentAddress, $remoteAddress);

                // 记录已经连上的
                // PeerManager::$peereds[$address] = true;

                PeerManager::addPeered($this->currentAddress, $remoteAddress);

                // $this->emit('connection', [
                //     ConnectionManager::$connections[$address]['connection'],
                //     $address,
                //     $this->server
                // ]);
                $this->getVirtualConnection($response, $localAddress, $remoteAddress);
            } else {
                echo "punched success already peered $remoteAddress" . PHP_EOL;
            }
        }
        // 收到远端数据了
        else if ($response->getStatusCode() === 416) {
            $virtualConnection = $this->getVirtualConnection($response, $localAddress, $remoteAddress);
            $data = $response->getHeaderLine('Data');
            $data = base64_decode($data);
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
            var_dump($data);

            $name = $data['name'] ?? '';
            $desc = $data['desc'] ?? '';
            static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " 417", [
                'class' => __CLASS__,
                'address' => $remoteAddress,
                'name' => $name,
                'desc' => $desc,
            ]);
            var_dump($name, $desc, $data);

            // 触发下信息



            $this->getVirtualConnection($response, $localAddress, $remoteAddress);

        }
        // else if ($response->getStatusCode() === 300) {
        //     $this->server->send("HTTP/1.1 301 OK\r\n".$this->header, $address);
        // } 
        // 收到远端的pong
        else if ($response->getStatusCode() === 301) {
            if ($remoteAddress != $this->serverAddress) {

                // 一端能连接对方，但对方连接不到自己，这种情况下，能ping通，就可以连接上
                // if (!in_array($address, array_keys(PeerManager::$peereds))) {
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
                    // PeerManager::$peereds[$address] = true;
                    PeerManager::addPeered($localAddress, $remoteAddress);
                    $this->getVirtualConnection($response, $localAddress, $remoteAddress);
                }
            }
        } else {
            echo "ignore other response code" . PHP_EOL;
        }
    }

    protected function punchTcpPeer($peer, $times = 0, $currentNumber = 0)
    {
        if ($currentNumber != $this->currentTcpNumber) {
            static::getLogger()->error("P2pTunnel::" . __FUNCTION__ . " currentNumber", [
                'class' => __CLASS__,
                "bindto" => $this->localAddress,
                'peer' => $peer,
                'currentNumber' => $currentNumber,
                '$this->currentTcpNumber' => $this->currentTcpNumber,
            ]);
            return;
        }

        if ($times >= 10) {
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
                "bindto" => $this->localAddress
            ]
        ]))->connect($peer)->then(function ($connection) use ($peer) {

            $localAddress = $connection->getLocalAddress();
            $remoteAddress = $connection->getRemoteAddress();


            $connection->protocol = 'p2p-tcp';
            $data = [
                'name' => $this->config['name'] ?? 'tcp',
                'desc' => $this->config['desc'] ?? 'tcp',
            ];

            $data = base64_encode(json_encode($data));

            $connection->write("HTTP/1.1 417 OK\r\nData: $data\r\n" . $this->header);

            static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " connected", [
                'class' => __CLASS__,
                'peer' => $peer,
                'address' => $connection->getRemoteAddress()
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

                PeerManager::addConnection($localAddress, $remoteAddress, $connection);
                $parseBuffer->handleBuffer($data);
            });

            echo ('tcp connection:' . $remoteAddress . "\n");


            PingPong::pingPong($connection, $peer, $this->header);
            $connection->on('close', function () use ($connection, $localAddress, $remoteAddress, $peer) {
                echo 'close ' . $connection->getRemoteAddress() . PHP_EOL;
                PeerManager::removePeered($localAddress, $remoteAddress);
                PeerManager::removeConnection($localAddress, $remoteAddress);
            });
        }, function ($e) use ($localAddress, $remoteAddress, $times, $currentNumber) {
            static::getLogger()->error($e->getMessage(), [
                'class' => __CLASS__,
                "bindto" => $this->localAddress,
                'currentNumber' => $currentNumber,
                'local_address' => $localAddress,
                'peer' => $remoteAddress,
                'times' => $times,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            if (!PeerManager::hasPeered($localAddress,$remoteAddress)) {
                \React\EventLoop\Loop::addTimer(0.001, function () use ($remoteAddress, $times, $currentNumber) {
                    self::punchTcpPeer($remoteAddress, $times, $currentNumber);
                });
            } else {
                static::getLogger()->debug("P2pTunnel::" . __FUNCTION__ . " hasTcpPeered", [
                    'class' => __CLASS__,
                    'peer' => $remoteAddress,
                    'time' => $times,
                ]);
            }

            return $e;
        })->otherwise(function ($e) use ($localAddress,$remoteAddress, $times, $currentNumber) {
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
            if (!PeerManager::hasPeered($localAddress, $remoteAddress)) {
                \React\EventLoop\Loop::addTimer(0.001, function () use ($remoteAddress, $times, $currentNumber) {
                    self::punchTcpPeer($remoteAddress, $times, $currentNumber);
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
            // $connection = ConnectionManager::$connections[$address]['connection'];
            $connection = PeerManager::getConnection($localAddress, $address);
            var_dump($localAddress, $address);

            $read = new ThroughStream;
            $write = new ThroughStream;

            $write->on('data', function ($data) use ($connection) {
                $data = base64_encode($data);
                $connection->write("HTTP/1.1 416 OK\r\nData: {$data}\r\n\r\n");
            });

            $virtualConnection = new CompositeConnectionStream($read, $write, new Connection(
                $this->server->getLocalAddress(),
                $address
            ), $connection->protocol == 'p2p-tcp' ? 'p2p-tcp' : 'p2p-udp');

            $connection->on('close', function () use ($virtualConnection, $localAddress, $address) {
                PeerManager::removeConnection($localAddress, $address);
                $virtualConnection->close();
            });

            $virtualConnection->on('close', function () use ($connection, $address) {
                $connection->close();
            });

            $data = [
                'name' => $this->config['name'] ?? '',
                'desc' => $this->config['desc'] ?? '',
            ];
            $data = base64_encode(json_encode($data));
            $connection->write("HTTP/1.1 417 OK\r\nData: $data\r\n" . $this->header);

            PeerManager::addVirtualConnection($localAddress, $address, $virtualConnection);


            if ($address != $this->serverAddress) {
                static::getLogger()->debug("P2pTunnel::" . __FUNCTION__, [
                    'class' => __CLASS__,
                    'address' => $address,
                    'server_ip_and_port' => $this->serverAddress,
                ]);
                $this->emit('connection', [$virtualConnection, $response, $address]);
                // 底层连接已经ping pong了，这里不需要再ping pong了
                // PingPong::pingPong($virtualConnection, $address);
            }
        }
        return PeerManager::getVirtualConnection($localAddress, $address);
    }
}
