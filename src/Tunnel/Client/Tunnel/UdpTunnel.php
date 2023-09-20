<?php

namespace Wpjscc\Penetration\Tunnel\Client\Tunnel;

use React\Socket\ConnectorInterface;
use Wpjscc\Penetration\CompositeConnectionStream;
use React\Stream\ThroughStream;


class UdpTunnel implements ConnectorInterface
{

    public function connect($uri)
    {
        return (new \React\Datagram\Factory())->createClient($uri)->then(function (\React\Datagram\Socket $client) {
            echo "Connected!\n";
            $read = new ThroughStream;
            $write = new ThroughStream;

            $write->on('data', function ($data) use ($client) {
                var_dump('sendDataToServer', $data);
                $client->send($data);
            });

            $contection = new CompositeConnectionStream($read, $write, $client);
            $client->on('message', function ($msg) use ($read) {
                var_dump('receiveDataFromServer', $msg);
                $read->write($msg);
            });

            $client->on('error', function ($error, $client) use ($contection) {
                echo "Connection closed\n";
                echo microtime(true)."\n";

                if ($contection->isReadable()) {
                    $contection->close();
                }
            });

            $contection->on('close', function () use ($client) {
                echo "Connection closed11\n";
                echo microtime(true)."\n";
                $client->close();
            });

            return $contection;
        }, function ($e) {
            echo "Could not connect: {$e->getMessage()}\n";
            return $e;
        });
    }
    
   
}