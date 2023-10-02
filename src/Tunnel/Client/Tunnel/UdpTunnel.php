<?php

namespace Wpjscc\Penetration\Tunnel\Client\Tunnel;

use React\Socket\ConnectorInterface;
use Wpjscc\Penetration\CompositeConnectionStream;
use React\Stream\ThroughStream;


class UdpTunnel implements ConnectorInterface, \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    public function connect($uri)
    {
        $protocol = 'udp';
        static::getLogger()->info(__FUNCTION__, [
            'class' => __CLASS__,
            'uri' => $uri,
            'protocol' => $protocol,
        ]);
        return (new \React\Datagram\Factory())->createClient($uri)->then(function (\React\Datagram\Socket $client) use ($uri, $protocol) {
            static::getLogger()->info("Connected!", [
                'class' => __CLASS__,
                'uri' => $uri,
                'protocol' => $protocol,
            ]);
            $read = new ThroughStream;
            $write = new ThroughStream;

            $write->on('data', function ($data) use ($client, $uri, $protocol) {
                static::getLogger()->info('sendDataToServer', [
                    'class' => __CLASS__,
                    'uri' => $uri,
                    'protocol' => $protocol,
                    'length' => strlen($data),
                ]);
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
            $client->on('message', function ($msg) use ($read, $uri, $protocol) {
                static::getLogger()->info('receiveDataFromServer', [
                    'class' => __CLASS__,
                    'uri' => $uri,
                    'protocol' => $protocol,
                    'length' => strlen($msg),
                ]);
                $read->write($msg);
            });

            $client->on('error', function ($error, $client) use ($contection, $uri, $protocol) {
                static::getLogger()->error($error->getMessage(), [
                    'class' => __CLASS__,
                    'uri' => $uri,
                    'protocol' => $protocol,
                    'file' => $error->getFile(),
                    'line' => $error->getLine(),
                ]);
                $client->send("POST /close HTTP/1.1\r\n\r\n");
                // $client->close();
            });

            $contection->on('close', function () use ($client, $uri, $protocol) {
                static::getLogger()->info('connectionClosed-2', [
                    'uri' => $uri,
                    'protocol' => $protocol,
                ]);
                \React\EventLoop\Loop::addPeriodicTimer(0.001, function () use ($client) {
                    $client->close();
                });
            });

            return $contection;
        }, function ($e) use ($uri, $protocol) {
            static::getLogger()->error($e->getMessage(), [
                'uri' => $uri,
                'protocol' => $protocol,
                'current_file' => __FILE__,
                'current_line' => __LINE__,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $e;
        });
    }
}
