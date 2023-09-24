<?php

namespace Wpjscc\Penetration\Tunnel\Server\Tunnel;


use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;
use Wpjscc\Penetration\CompositeConnectionStream;

class WebsocketTunnel extends EventEmitter implements ServerInterface,\Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;
    private $server;

    public function __construct($httpHost = 'localhost', $port = 8080, $address = '127.0.0.1', LoopInterface $loop = null, $context = array(), $socket = null)
    {
        $loop = $loop ?: Loop::get();
        $app = new WebsocketApp($httpHost, $port, $address, $loop, $context, $socket);
        $this->server = $app->getSocket();

        $controller = new WebsocketController();
        $controller->on('connection', function ($conn) {
            $this->emit('connection', array($conn));
        });
        $app->route('/tunnel', $controller, array('*'));
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