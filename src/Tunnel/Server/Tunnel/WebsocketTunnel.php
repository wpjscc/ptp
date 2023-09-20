<?php

namespace Wpjscc\Penetration\Tunnel\Server\Tunnel;


use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use React\EventLoop\LoopInterface;
use Ratchet\App;
use React\EventLoop\Loop;

class WebsocketTunnel extends EventEmitter implements ServerInterface
{
    private $server;

    public function __construct($httpHost = 'localhost', $port = 8080, $address = '127.0.0.1', LoopInterface $loop = null, $context = array())
    {
        $loop = $loop ?: Loop::get();
        $app = new App($httpHost, $port, $address, $loop, $context);
        $controller = new WebsocketController();
        $controller->on('connection', function ($conn) {
            $this->emit('connection', array($conn));
        });
        $app->route('/tunnel', $controller, array('*'));
    }
    

    public function getAddress()
    {
        // return $this->server->getAddress();
    }

    public function pause()
    {
        // $this->server->pause();
    }

    public function resume()
    {
        // $this->server->resume();
    }

    public function close()
    {
        // $this->server->close();
    }
}