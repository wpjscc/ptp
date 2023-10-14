<?php

namespace Wpjscc\PTP\Tunnel\Server\Tunnel;

use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use RingCentral\Psr7;
use Ramsey\Uuid\Uuid;
use React\Stream\ThroughStream;
use Wpjscc\PTP\CompositeConnectionStream;
use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Utils\ParseBuffer;

class SingleTunnel extends EventEmitter implements ServerInterface, \Wpjscc\PTP\Tunnel\SingleTunnelInterface,\Wpjscc\PTP\Log\LogManagerInterface
{

    use \Wpjscc\PTP\Log\LogManagerTraitDefault;
    private $connections = array();

    private $vuuids = [];

    private $connection;

    private $buffer = '';

    // code 310~320


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


    public function getAddress()
    {

    }

    public function pause()
    {

    }

    public function resume()
    {

    }

    public function close()
    {
        static::getLogger()->debug("SingleTunnel::".__FUNCTION__, [
            'class' => __CLASS__,
        ]);
        foreach ($this->connections as $connection) {
            $connection->close();
        }
    }

    protected function handleResponse($response)
    {
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
        // client ping
        elseif ($response->getStatusCode() === 300) {
            static::getLogger()->info('server ping', [
                'code' => $response->getStatusCode(),
            ]);
            $this->connection->write("HTTP/1.1 301 OK\r\n\r\n");
        } 
        // client pong
        elseif ($response->getStatusCode() === 301) {
            static::getLogger()->debug("SingleTunnel::".__FUNCTION__." client pong", [
                'class' => __CLASS__,
                'response' => Helper::toString($response)
            ]);
        }
        
        else {
            // ignore other response code
            static::getLogger()->warning("single tunnel ignore response", [
                'class' => __CLASS__,
                'response' => Helper::toString($response)
            ]);

        }
    }

    protected function createConnection($response)
    {
        $uuid = $response->getHeaderLine('Uuid');



        if (!Uuid::isValid($uuid)) {
            static::getLogger()->error("SingleTunnel::".__FUNCTION__." uuid is invalid", [
                'class' => __CLASS__,
                'uuid' => $uuid,
            ]);
            return;
            // ignore
        }

        $read = new ThroughStream;
        $write = new ThroughStream;
        $contection = new CompositeConnectionStream($read, $write, null, 'single');

        $write->on('data', function ($data) use ($uuid)  {
            static::getLogger()->debug('single tunnel send data', [
                'uuid' => $uuid,
                'length' => strlen($data),
            ]);
            $data = base64_encode($data);
            $this->connection->write("HTTP/1.1 311 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
        });

        $read->on('close', function () use ($uuid) {
            static::getLogger()->debug('single tunnel close', [
                'uuid' => $uuid,
            ]);
            $this->connection->write("HTTP/1.1 312 OK\r\nUuid: {$uuid}\r\n\r\n");
            unset($this->connections[$uuid]);
        });

        $this->connections[$uuid] = $contection;
        static::getLogger()->debug("SingleTunnel::".__FUNCTION__, [
            'uuid' => $uuid,
            'response' => Helper::toString($response)
        ]);

        $contection->uuid = $uuid;

        $this->emit('connection', array($contection));

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
            $data = base64_decode($response->getHeaderLine('Data'));
            $length = strlen($data);
            static::getLogger()->warning('single tunnel after close receive data connection not found', [
                'uuid' => $uuid,
                'length' => $length,
            ]);
            return;
            // ignore
        }

        $data = base64_decode($response->getHeaderLine('Data'));
        $length = strlen($data);

        static::getLogger()->debug('single tunnel receive data', [
            'uuid' => $uuid,
            'length' => $length,
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

        static::getLogger()->debug('single tunnel close', [
            'uuid' => $uuid,
        ]);


        $this->connections[$uuid]->close();

        unset($this->connections[$uuid]);

    }
}