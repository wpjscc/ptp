<?php

namespace Wpjscc\PTP\Tunnel\Client\Tunnel;

use React\Socket\ConnectorInterface;
use function Ratchet\Client\connect;
use Wpjscc\PTP\CompositeConnectionStream;
use React\Stream\ThroughStream;
use RingCentral\Psr7;

class WebsocketTunnel implements ConnectorInterface, \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    public function connect($uri)
    {

        $protocol = parse_url($uri, PHP_URL_SCHEME);
        static::getLogger()->info('starting', [
            'uri' => $uri,
            'protocol' => $protocol,
        ]);
        return connect($uri . '/tunnel')->then(function ($conn) use ($uri, $protocol) {
            static::getLogger()->info("Connected!", [
                'uri' => $uri,
                'protocol' => $protocol,
            ]);
            $read = new ThroughStream;
            $write = new ThroughStream;
            $write->on('data', function ($data) use ($conn, $uri, $protocol) {
                static::getLogger()->debug('sendDataToServer', [
                    'uri' => $uri,
                    'protocol' => $protocol,
                    'length' => strlen($data),
                ]);
                $conn->send(base64_encode($data));
            });

            $contection = new CompositeConnectionStream($read, $write, $conn->getStream(), $protocol);
            $conn->on('message', function ($msg) use ($read, $uri, $protocol) {
                static::getLogger()->info('receiveDataFromServer', [
                    'uri' => $uri,
                    'protocol' => $protocol,
                    'length' => strlen($msg),
                ]);
                $read->write(base64_decode($msg));
            });

            $conn->on('close', function () use ($contection, $uri, $protocol) {
                static::getLogger()->info('connectionClosed-1', [
                    'uri' => $uri,
                    'protocol' => $protocol,
                ]);
                if ($contection->isReadable()) {
                    $contection->close();
                }
            });
            $contection->on('close', function () use ($conn, $uri, $protocol) {
                static::getLogger()->info('connectionClosed-2', [
                    'uri' => $uri,
                    'protocol' => $protocol,
                ]);

                $conn->close();
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
