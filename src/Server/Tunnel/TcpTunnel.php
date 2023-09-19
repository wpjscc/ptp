<?php

namespace Wpjscc\Penetration\Server\Tunnel;


use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use React\EventLoop\LoopInterface;


class TcpTunnel extends EventEmitter implements ServerInterface
{
    private $server;

    public function __construct($uri, array $context = array(), LoopInterface $loop = null)
    {
        $this->server = new \React\Socket\SocketServer($uri, $context, $loop);
        echo "TcpTunnel\n";
        echo "$uri\n";
        $this->server->on('connection', function ($connection) {
            echo "connection\n";
            $this->emit('connection', array($connection));
        });
    }
    

    public function getAddress()
    {
        return $this->server->getAddress();
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