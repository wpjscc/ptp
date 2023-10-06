<?php

namespace Wpjscc\Penetration\Tunnel\Server\Tunnel;


use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use React\EventLoop\LoopInterface;
use React\Datagram\Factory;
use Wpjscc\Penetration\CompositeConnectionStream;
use Wpjscc\Penetration\Connection;
use Wpjscc\Penetration\Helper;
use React\Stream\ThroughStream;
use Wpjscc\Kcp\KCP;
use Wpjscc\Bytebuffer\Buffer;


class UdpTunnel extends EventEmitter implements ServerInterface,\Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;
    private $server;
    private $isKcp = false;

    private $connections = array();

    public function __construct($uri, LoopInterface $loop = null, $callback = null)
    {
        $factory = new Factory($loop);

        $factory->createServer($uri)->then(function (\React\Datagram\Socket $server) use ($callback) {
            static::getLogger()->debug("UdpTunnel::" . __FUNCTION__, [
                'class' => __CLASS__,
            ]);
            $this->server = $server;

            $start = Helper::getMillisecond();

            if ($callback) {
                call_user_func($callback, $server, $this, $start);
            }
            $server->on('message', function ($message, $address, $server) use ($start) {
                var_dump('message', $message, $address);
                $this->createConnection($message, $address, $server, $start);
            });

            $server->on('error', function ($e, $server) {
                static::getLogger()->error($e->getMessage().'-1', [
                    'class' => __CLASS__,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(), 

                ]);
            });

        }, function ($e) {
            static::getLogger()->error($e->getMessage().'-2', [
                'class' => __CLASS__,
                'file' => $e->getFile(),
                'line' => $e->getLine(), 

            ]);
            return $e;
        })->otherwise(function ($e) {
            static::getLogger()->error($e->getMessage().'-3', [
                'class' => __CLASS__,
                'file' => $e->getFile(),
                'line' => $e->getLine(), 
            ]);
            return $e;
        });
    }

    public function createConnection($message, $address, $server, $start)
    {
        if (!isset($this->connections[$address])) {
            if ($this->isKcp) {
                $kcp = new KCP(11, 22, function(Buffer $buffer) use ($server, $address) {
                    // static::getLogger()->debug("sendToClient", [
                    //     'class' => __CLASS__,
                    //     'buffer' => (string)$buffer,
                    //     'buffer_hex' => bin2hex((string)$buffer),
                    //     'address' => $address,
                    // ]);
                    $server->send((string)$buffer, $address);
                });
                $receiveBuffer = Buffer::allocate(1024 * 1024 * 50);
                $this->connections[$address]['kcp'] = $kcp;
                $this->connections[$address]['receiveBuffer'] = $receiveBuffer;
            }

            $read = new ThroughStream;
            $write = new ThroughStream;
            $contection = new CompositeConnectionStream($read, $write, new Connection(
                $server->getLocalAddress(),
                $address
            ), 'udp');

          
            $write->on('data', function ($data) use ($server, $address, $start) {
                if ($this->isKcp) {
                    $kcp = $this->connections[$address]['kcp'];
                    $kcp->send(Buffer::new($data));
                    // $kcp->update(Helper::getMillisecond() - $start);
                    $kcp->flush();

                } else {
                    $server->send($data, $address);
                }
            });
            $kcp = $this->connections[$address]['kcp'] ?? null;
            if ($kcp) {
                $timer = \React\EventLoop\Loop::addPeriodicTimer(0.001, function () use ($kcp, $start) {
                    if ($kcp->getWaitSnd() > 0){
                        static::getLogger()->debug('kcpUpdate', [
                            'class' => __CLASS__,
                            'start' => $start,
                            'millisecond' => Helper::getMillisecond(),
                            'diff' => Helper::getMillisecond() - $start,
                            'wait_snd' => $kcp->getWaitSnd(),
                        ]);
                        $kcp->update(Helper::getMillisecond() - $start);
                    }
                });
                $read->on('close', function () use ($address, $timer) {
                    if ($timer) {
                        \React\EventLoop\Loop::cancelTimer($timer);
                    }
                });
    
                // $kcp->setNodelay(2, 2, true);
                // $kcp->setInterval(10);
                // $kcp->setRxMinRto(10);
                // $kcp->setFastresend(1);

                //  $kcp->setNodelay(2, 2, true);
                // $kcp->setInterval(10);
                // $kcp->setRxMinRto(10);
                // $kcp->setMtu(1024 * 1024 * 2);
                // $kcp->setInterval(1);
                // $kcp->setRxMinRto(5);
                // $kcp->setFastresend(1);
            }

            $read->on('close', function () use ($address) {
                unset($this->connections[$address]);
            });

            $this->connections[$address]['connection'] = $contection;
            
            $this->emit('connection', array($contection, $address, $server));

            
        } else {
            $contection = $this->connections[$address]['connection'];
            if ($this->isKcp) {
                $kcp = $this->connections[$address]['kcp'];
                $receiveBuffer = $this->connections[$address]['receiveBuffer'];
            }
        }

        if ($message !=='' ) {
            if ($this->isKcp) {
                $result = $kcp->input(Buffer::new($message));
                if ($result < 0) {
                    static::getLogger()->error("UdpTunnel::".__FUNCTION__, [
                        'message' => $message,
                        'address' => $address,
                        'result' => $result,
                    ]);
                }
                $kcp->update(Helper::getMillisecond() - $start);
                // $kcp->flush();
                $size = 0;
                // static::getLogger()->debug("收到信息", [
                //     'message' => $message,
                //     'address' => $address,
                //     'result' => $result,
                // ]);
                do {
                    
                    $size = $kcp->recv($receiveBuffer);
                    // static::getLogger()->debug("开始解码", [
                    //     'message_hex' => bin2hex($message),
                    //     'address' => $address,
                    //     'result' => $result,
                    //     'size' => $size,
                    // ]);
                    if ($size > 0) {
                        $data = $receiveBuffer->slice(0, $size);
                        static::getLogger()->debug("解码成功", [
                            'data' => (string)$data,
                            'address' => $address,
                            'size' => $size,
                        ]);
                        $contection->emit('data', array($data, $address));
                    }
                } while ($size >= 0);
                
            } else {
                $contection->emit('data', array($message, $address));
            }
        }
        
        $contection->activeTime = time();
    }


    public function getAddress()
    {
        return $this->server->getLocalAddress();
    }

    public function pause()
    {
        $this->server->pause();
    }

    public function resume()
    {
        $this->server->resume();
    }

    public function close()
    {
        $this->server->close();
        foreach ($this->connections as $contection) {
            $contection->close();
        }
    }

    public function supportKcp()
    {
        $this->isKcp = true;
    }
}