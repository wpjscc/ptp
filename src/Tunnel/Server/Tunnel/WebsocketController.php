<?php

namespace Wpjscc\Penetration\Tunnel\Server\Tunnel;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Evenement\EventEmitter;
use Wpjscc\Penetration\CompositeConnectionStream;
use React\Stream\ThroughStream;

class WebsocketController extends EventEmitter implements  MessageComponentInterface
{

    protected $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $read = new ThroughStream;
        $write = new ThroughStream;

        $write->on('data', function ($data) use ($conn) {
            $conn->send(base64_encode($data));
        });

        $contection = new CompositeConnectionStream($read, $write);

        $this->clients->attach($conn, $read);
        $this->emit('connection', array($contection));

    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $read = $this->clients[$from];
        $read->write(base64_decode($msg));
    }

    public function onClose(ConnectionInterface $conn)
    {
        echo "Connection {$conn->resourceId} has disconnected\n";
        $read = $this->clients[$conn];
        $read->close();
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()}\n";
        $read = $this->clients[$conn];
        $read->emit('error', array($e));
        $conn->close();
    }
}