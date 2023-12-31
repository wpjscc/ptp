<?php

namespace Wpjscc\PTP\Tunnel\Server\Tunnel;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Evenement\EventEmitter;
use Wpjscc\PTP\CompositeConnectionStream;
use React\Stream\ThroughStream;

class WebsocketController extends EventEmitter implements  MessageComponentInterface,\Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

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

        $_connection = $conn->getConnection()->getConnection();

        $localAddress = $_connection->getLocalAddress();
        $protocol = strpos($localAddress, 'tls') === 0 ? 'wss' : 'ws';


        $contection = new CompositeConnectionStream($read, $write, $_connection, $protocol);

        $this->clients->attach($conn, $contection);
        $this->emit('connection', array($contection));

    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $contection = $this->clients[$from];
        static::getLogger()->debug('receiveDataFromClient', [
            'uri' => $contection->getRemoteAddress(),
            'length' => strlen(base64_decode($msg)),
        ]);

        $contection->emit('data', array(base64_decode($msg)));
    }

    public function onClose(ConnectionInterface $conn, ...$args)
    {
        $contection = $this->clients[$conn];
        echo "Connection {$conn->resourceId} ".$contection->getRemoteAddress()." has disconnected\n";
        $contection->end();
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()}\n";
        $contection = $this->clients[$conn];
        $contection->emit('error', array($e));
        $conn->close();
    }
}