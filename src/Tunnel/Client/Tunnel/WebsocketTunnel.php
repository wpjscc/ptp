<?php

namespace Wpjscc\Penetration\Tunnel\Client\Tunnel;

use React\Socket\ConnectorInterface;
use function Ratchet\Client\connect;
use Wpjscc\Penetration\CompositeConnectionStream;
use React\Stream\ThroughStream;

class WebsocketTunnel implements ConnectorInterface
{

    public function connect($uri)
    {
        var_dump($uri.'/tunnel');
        $protocol = parse_url($uri, PHP_URL_SCHEME);
        
        return connect($uri.'/tunnel')->then(function ($conn) use ($protocol) {
            echo "Connected!\n";
            $read = new ThroughStream;
            $write = new ThroughStream;
            $write->on('data', function ($data) use ($conn) {
                var_dump('sendDataToServer', $data);
                $conn->send(base64_encode($data));
            });

            $contection = new CompositeConnectionStream($read, $write, $conn->getStream(), $protocol);
            $conn->on('message', function ($msg) use ($read) {
                echo ('websocket tunnel receiveDataFromServer');
                $read->write(base64_decode($msg));
            });

            $conn->on('close', function () use ($contection) {
                echo "Connection closed\n";
                echo microtime(true)."\n";

                if ($contection->isReadable()) {
                    $contection->close();
                }
            });
            $contection->on('close', function () use ($conn) {
                echo "Connection closed11\n";
                echo microtime(true)."\n";
                $conn->close();

            });

            return $contection;
        }, function ($e) {
            echo "Could not connect: {$e->getMessage()}\n";
            return $e;
        });
    }
    
   
}