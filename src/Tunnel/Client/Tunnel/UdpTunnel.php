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
                echo "sendDataToServer length ".strlen($data)." \n";
                //  65536 will report Unable to send packet: stream_socket_sendto(): Message too long
                // see React\Datagram\Buffer -> handleWrite
                // todo packet length > 65536
                $client->send($data);
                
                // if (strlen($data) > 512) {
                //     do {
                //         $client->send(substr($data, 0, 512));
                //         $data = substr($data, 512);
                //     } while ($data);
                // } else {
                //     $client->send($data);
                // }

            });

            $contection = new CompositeConnectionStream($read, $write, $client, 'udp');
            $client->on('message', function ($msg) use ($read) {
                // var_dump('receiveDataFromServer', $msg);
                $read->write($msg);
            });

            $client->on('error', function ($error, $client) use ($contection) {
                echo "Connection closed\n";
                echo $error->getMessage()."\n";
                echo microtime(true)."\n";

                $client->send("POST /close HTTP/1.1\r\n\r\n");


            });

            $contection->on('close', function () use ($client) {
                echo "Connection closed11\n";
                echo microtime(true)."\n";
                \React\EventLoop\Loop::addPeriodicTimer(0.001, function () use ($client) {
                    $client->close();
                });
            });

            return $contection;
        }, function ($e) {
            echo "Could not connect: {$e->getMessage()}\n";
            return $e;
        });
    }
    
   
}