<?php

namespace Wpjscc\Penetration\Tunnel\Server\Tunnel;


use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use React\EventLoop\LoopInterface;
use React\Datagram\Factory;
use Wpjscc\Penetration\CompositeConnectionStream;
use React\Stream\ThroughStream;


class UdpTunnel extends EventEmitter implements ServerInterface
{
    private $server;

    private $connections = array();

    public function __construct($uri, LoopInterface $loop = null)
    {
        $factory = new Factory($loop);

        $factory->createServer($uri)->then(function (\React\Datagram\Socket $server) {
            $this->server = $server;
            $server->on('message', function ($message, $address, $server) {
                if (!isset($this->connections[$address])) {
                    $read = new ThroughStream;
                    $write = new ThroughStream;
                    $contection = new CompositeConnectionStream($read, $write);

                    $write->on('data', function ($data) use ($server, $address) {
                        $server->send($data, $address);
                    });

                    $read->on('close', function () use ($address) {
                        unset($this->connections[$address]);
                    });

                    $this->connections[$address] = $contection;
                    $this->emit('connection', array($contection));
                } else {
                    $contection = $this->connections[$address];
                }
                $contection->emit('data', array($message));
            });
        });
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
    }
}