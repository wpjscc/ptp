<?php

namespace Wpjscc\PTP\Tunnel\Client\Tunnel;

use Evenement\EventEmitter;
use RingCentral\Psr7;
use Ramsey\Uuid\Uuid;
use React\Stream\ThroughStream;
use Wpjscc\PTP\CompositeConnectionStream;
use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Utils\ParseBuffer;

class SingleTunnel extends EventEmitter implements \Wpjscc\PTP\Log\LogManagerInterface, \Wpjscc\PTP\Tunnel\SingleTunnelInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    private $connections = array();
    private $connection;


    public function __construct()
    {
    }

    public function overConnection($connection)
    {
        $this->connection = $connection;
        $parseBuffer = new ParseBuffer;
        $parseBuffer->on('response', [$this, 'handleResponse']);
        $this->connection->on('data', [$parseBuffer, 'handleBuffer']);
        $this->connection->on('close', [$this, 'close']);
    }

    protected function handleResponse($response)
    {
        // 打开链接
        if ($response->getStatusCode() === 310) {
            $this->createConnection($response);
        }
        // 收到数据
        elseif ($response->getStatusCode() === 311) {
            $this->handleData($response);
        }
        // 关闭连接
        elseif ($response->getStatusCode() === 312) {
            $this->handleClose($response);
        }
        // 服务端ping
        elseif ($response->getStatusCode() === 300) {
            static::getLogger()->debug('server ping', [
                'code' => $response->getStatusCode(),
            ]);
            $this->connection->write("HTTP/1.1 301 OK\r\n\r\n");
        } 
        // server pong
        elseif ($response->getStatusCode() === 301) {
            static::getLogger()->debug("SingleTunnel::".__FUNCTION__." client pong", [
                'class' => __CLASS__,
                'response' => Helper::toString($response)
            ]);
        }
        else {
            // ignore other response code
            static::getLogger()->warning('ignore other response code', [
                'code' => $response->getStatusCode(),
            ]);
        }
    }


    protected function createConnection($response)
    {

        $uuid = $response->getHeaderLine('Uuid');
        $read = new ThroughStream;
        $write = new ThroughStream;
        $contection = new CompositeConnectionStream($read, $write, null,  'single');

        $write->on('data', function ($data) use ($uuid) {
            static::getLogger()->info('single tunnel send data', [
                'uuid' => $uuid,
                'length' => strlen($data),
            ]);

            // chunk 20k send 

            $length = strlen($data);
            if (($length/1024) > 20) {
               
                $chunk = 20 * 1024;
                $chunks = str_split($data, $chunk);
                foreach ($chunks as $k=>$chunk) {
                    static::getLogger()->debug('single tunnel send data chunk', [
                        'uuid' => $uuid,
                        'length' => strlen($chunk),
                    ]);
                    $data = base64_encode($chunk);
                        $vuuid = Uuid::uuid4()->toString();

                        for ($i=0; $i < 1; $i++) { 
                            $this->connection->write("HTTP/1.1 311 OK\r\nUuid: {$uuid}\r\nVuuid: {$vuuid}\r\nData: {$data}\r\n\r\n");
                        }
                }
                return;
            } else {
                static::getLogger()->debug('single tunnel send data[no chunk]', [
                    'uuid' => $uuid,
                    'length' => $length,
                ]);
            }
            
            $data = base64_encode($data);
            $vuuid = Uuid::uuid4()->toString();
            for ($i=0; $i < 1; $i++) { 
                $this->connection->write("HTTP/1.1 311 OK\r\nUuid: {$uuid}\r\nVuuid: {$vuuid}\r\nData: {$data}\r\n\r\n");
            }
        });

        $read->on('close', function () use ($uuid) {
            static::getLogger()->info('single tunnel close', [
                'uuid' => $uuid,
            ]);
            $this->connection->write("HTTP/1.1 312 OK\r\nUuid: {$uuid}\r\n\r\n");
            unset($this->connections[$uuid]);
        });

        $this->connections[$uuid] = $contection;

        static::getLogger()->notice("SingleTunnel::".__FUNCTION__, [
            'uuid' => $uuid,
            'response' => Helper::toString($response)
        ]);

        $this->emit('connection', array($contection, $response));


        $this->connection->write("HTTP/1.1 310 OK\r\nUuid: {$uuid}\r\n\r\n");
    }

    protected function handleData($response)
    {
        $uuid = $response->getHeaderLine('Uuid');

        if (!Uuid::isValid($uuid)) {
            static::getLogger()->warning('single tunnel receive data invalid uuid', [
                'uuid' => $uuid,
            ]);
            return;
            // ignore
        }

        if (!isset($this->connections[$uuid])) {
            static::getLogger()->warning('single tunnel receive data connection not found', [
                'uuid' => $uuid,
            ]);
            return;
            // ignore
        }

        $data = base64_decode($response->getHeaderLine('Data'));

        static::getLogger()->info('single tunnel receive data', [
            'uuid' => $uuid,
            'length' => strlen($data),
        ]);

        $this->connections[$uuid]->emit('data', array($data));
    }

    protected function handleClose($response)
    {
        $uuid = $response->getHeaderLine('Uuid');

        if (!Uuid::isValid($uuid)) {
            static::getLogger()->warning('single tunnel close invalid uuid', [
                'uuid' => $uuid,
            ]);
            return;
            // ignore
        }

        if (!isset($this->connections[$uuid])) {
            static::getLogger()->warning('single tunnel close connection not found', [
                'uuid' => $uuid,
            ]);
            return;
            // ignore
        }

        static::getLogger()->debug("SingleTunnel::".__FUNCTION__, [
            'uuid' => $uuid,
        ]);
        $this->connections[$uuid]->close();
    }

    public function close()
    {
        static::getLogger()->warning('single tunnel close', [
            'localAddress' => $this->connection->getLocalAddress(),
            'remoteAddress' => $this->connection->getRemoteAddress(),
        ]);

        foreach ($this->connections as $contection) {
            $contection->close();
        }
    }
}
