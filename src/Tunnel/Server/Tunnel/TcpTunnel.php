<?php

namespace Wpjscc\PTP\Tunnel\Server\Tunnel;


use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use React\EventLoop\LoopInterface;


class TcpTunnel extends EventEmitter implements ServerInterface,\Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;
    private $server;

    public function __construct($uri, array $context = array(), LoopInterface $loop = null)
    {
        $this->server = new \React\Socket\SocketServer($uri, $context, $loop);
        $this->server->on('connection', function ($connection) {
            $localAddress = $connection->getLocalAddress();
            $protocol = strpos($localAddress, 'tls') === 0 ? 'tls' : 'tcp';
            $connection->protocol = $protocol;
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