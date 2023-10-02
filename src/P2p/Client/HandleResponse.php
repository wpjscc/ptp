<?php

namespace Wpjscc\Penetration\P2p\Client;

use Ramsey\Uuid\Uuid;
use React\Stream\ThroughStream;
use Wpjscc\Penetration\CompositeConnectionStream;
use Wpjscc\Penetration\Tunnel\Server\Tunnel\SingleTunnel as ServerSingleTunnel;
use Wpjscc\Penetration\Tunnel\Client\Tunnel\SingleTunnel as ClientSingleTunnel;
use Wpjscc\Penetration\Client\ClientManager; 
use Evenement\EventEmitter;

class HandleResponse extends EventEmitter implements \Wpjscc\Penetration\Log\LogManagerInterface
{

    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    protected $sConnections = [];
    protected $cConnections = [];

    protected $connection;

    protected $address;

    protected $config;

    protected $parseBuffer;

    public function __construct($connection, $address, $config)
    {
        $this->connection = $connection;
        $this->address = $address;
        $this->config = $config;

        $this->parseBuffer = new \Wpjscc\Penetration\Utils\ParseBuffer();
        $this->parseBuffer->on('response', [$this, 'response']);
        $connection->on('data', function ($data) {
            // var_dump('handleResponseData321321321321321', $data);
            $this->parseBuffer->handleBuffer($data);
        });

        $connection->on('close', function () {
            static::getLogger()->debug(__CLASS__ . 'connection close');
            foreach ($this->sConnections as $connection) {
                $connection->close();
            }
            foreach ($this->cConnections as $connection) {
                $connection->close();
            }
        });
    }



    // if ($response->getStatusCode() == 199) {
    //     $uuid = Uuid::uuid4()->toString();
    //     $data = "HTTP/1.1 310 OK\r\nUuid:{$uuid}"."\r\n\r\n";
    //     $data = base64_encode($data);
    //     $this->connection->write("HTTP/1.1 201 OK\r\nUuid: {$uuid}Data: {$data}\r\n\r\n");
    // } 
    public function response($response)
    {

        // p2p 相当于服务端收到请求了
        if ($response->getStatusCode() == 200) {
            $this->handleRequest($response);
        }

        // 收到 p2p 的响应 ，相当于客户端
        else if ($response->getStatusCode() == 201) {
            $this->handleResponse($response);
        } else {
            static::getLogger()->warning(__CLASS__ . 'response invalid status code', [
                'code' => $response->getStatusCode(),
            ]);
        }
        
    }

    // server 收到请求
    public function handleRequest($response)
    {
        $uuid = $response->getHeaderLine('uuid');
      
        if (!Uuid::isValid($uuid)) {
            static::getLogger()->warning(__CLASS__ . 'handleRequest invalid uuid', [
                'uuid' => $uuid,
            ]);
            return;
            // ignore
        }

        static::getLogger()->info(__CLASS__ . 'handleRequest', [
            'uuid' => $uuid,
        ]);

        if (!isset($this->sConnections[$uuid])) {
            $read = new ThroughStream();
            $write = new ThroughStream();
            $write->on('data', function ($data) use ($uuid) {
                $data = base64_encode($data);
                $this->connection->write("HTTP/1.1 201 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
            });

            $contection = new CompositeConnectionStream($read, $write, null, 'p2p_single');
            $this->sConnections[$uuid] = $contection;
            $contection->on('close', function () use ($uuid) {
                unset($this->sConnections[$uuid]);
            });

            $singleTunnel = new ServerSingleTunnel();
            $singleTunnel->overConnection($contection);
            $singleTunnel->on('connection', function ($singleConnection) {
                $this->emit('connection', [$singleConnection]);
            });

        }

        $data = $response->getHeaderLine('data');
        $data = base64_decode($data);
        $this->sConnections[$uuid]->emit('data', [$data]);

    }

    // client 收到响应
    public function handleResponse($response)
    {
        $uuid = $response->getHeaderLine('uuid');
        if (!Uuid::isValid($uuid)) {
            static::getLogger()->notice(__CLASS__ . 'handleResponse invalid uuid', [
                'uuid' => $uuid,
            ]);
            return;
            // ignore
        }

        if (!isset($this->cConnections[$uuid])) {
            $read = new ThroughStream();
            $write = new ThroughStream();
            $write->on('data', function ($data) use ($uuid) {
                $data = base64_encode($data);
                $this->connection->write("HTTP/1.1 200 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
            });

            $contection = new CompositeConnectionStream($read, $write, null, 'p2p_single');
            $this->cConnections[$uuid] = $contection;
            $contection->on('close', function () use ($uuid) {
                unset($this->cConnections[$uuid]);
            });

            $singleTunnel = new ClientSingleTunnel();
            $singleTunnel->overConnection($contection);
            $config = $this->config;
            $singleTunnel->on('connection', function ($connection, $response) use (&$config) {
                $buffer = '';
                // 处理本地服务
                ClientManager::handleLocalConnection($connection, $config, $buffer, $response);
            });
        }

        $data = $response->getHeaderLine('data');
        $data = base64_decode($data);
        $this->cConnections[$uuid]->emit('data', [$data]);
    }
}
