<?php

namespace Wpjscc\Penetration\Tunnel\Server\Tunnel;


use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use React\EventLoop\LoopInterface;
use React\Datagram\Factory;
use Wpjscc\Penetration\CompositeConnectionStream;
use Wpjscc\Penetration\Connection;
use React\Stream\ThroughStream;


class UdpTunnel extends EventEmitter implements ServerInterface,\Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;
    private $server;

    private $connections = array();

    public function __construct($uri, LoopInterface $loop = null, $callback = null)
    {
        $factory = new Factory($loop);

        $factory->createServer($uri)->then(function (\React\Datagram\Socket $server) use ($callback) {
            $this->server = $server;
            if ($callback) {
                call_user_func($callback, $server);
            }
            $server->on('message', function ($message, $address, $server) {
                if (!isset($this->connections[$address])) {
                    $read = new ThroughStream;
                    $write = new ThroughStream;
                    $contection = new CompositeConnectionStream($read, $write, new Connection(
                        $server->getLocalAddress(),
                        $address
                    ), 'udp');

                    $write->on('data', function ($data) use ($server, $address) {
                        $server->send($data, $address);
                    });

                    $read->on('close', function () use ($address) {
                        unset($this->connections[$address]);
                    });

                    $this->connections[$address] = $contection;
                    $this->emit('connection', array($contection, $address, $server));
                } else {
                    $contection = $this->connections[$address];
                }
                $contection->activeTime = time();
                // if (strpos($message, 'POST /close HTTP/1.1') !== false) {
                //     var_dump('receiveDataFromCLient', $message);
                // }
                // var_dump('receiveDataFromCLient', $message);
                $contection->emit('data', array($message, $address));
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
        foreach ($this->connections as $contection) {
            $contection->close();
        }
    }
}