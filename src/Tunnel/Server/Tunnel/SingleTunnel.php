<?php

namespace Wpjscc\Penetration\Tunnel\Server\Tunnel;

use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use RingCentral\Psr7;
use Ramsey\Uuid\Uuid;
use React\Stream\ThroughStream;
use Wpjscc\Penetration\CompositeConnectionStream;
use Wpjscc\Penetration\Helper;

class SingleTunnel extends EventEmitter implements ServerInterface, \Wpjscc\Penetration\Tunnel\SingleTunnelInterface,\Wpjscc\Penetration\Log\LogManagerInterface
{

    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;
    private $connections = array();
    private $connection;

    private $buffer = '';

    // code 310~320


    public function __construct()
    {
       

    }

    public function overConnection($connection)
    {
        $this->connection = $connection;

        $this->connection->on('data', [$this, 'parseBuffer']);
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
        static::getLogger()->info("SingleTunnel::".__FUNCTION__, [
            'class' => __CLASS__,
        ]);
        foreach ($this->connections as $connection) {
            $connection->close();
        }
    }

    protected function parseBuffer($buffer)
    {

        if ($buffer === '') {
            return;
        }
        
        $this->buffer .= $buffer;

        $pos = strpos($this->buffer, "\r\n\r\n");
        if ($pos !== false) {
            $httpPos = strpos($this->buffer, "HTTP/1.1");
            if ($httpPos === false) {
                $httpPos = 0;
            }
            try {
                $response = Psr7\parse_response(substr($this->buffer, $httpPos, $pos-$httpPos));
            } catch (\Exception $e) {
                // invalid response message, close connection
                static::getLogger()->error($e->getMessage(), [
                    'class' => __CLASS__,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'buffer' => substr($this->buffer, $httpPos, $pos-$httpPos)
                ]);

                $this->buffer = substr($this->buffer, $pos + 4);
                

                // error
                // todo close connection
                return;
            }

            $this->buffer = substr($this->buffer, $pos + 4);

            // 创建通道成功

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
            
            else {
                // ignore other response code
                static::getLogger()->warning("single tunnel ignore response", [
                    'class' => __CLASS__,
                    'response' => Helper::toString($response)
                ]);

            }

            // 继续解析
            $this->parseBuffer(null);
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
            static::getLogger()->notice('single tunnel send data', [
                'uuid' => $uuid,
                'length' => strlen($data),
            ]);
            $data = base64_encode($data);
            $this->connection->write("HTTP/1.1 311 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
        });

        $read->on('close', function () use ($uuid) {
            static::getLogger()->notice('single tunnel close', [
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

        static::getLogger()->info('single tunnel receive data', [
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

        static::getLogger()->info('single tunnel close', [
            'uuid' => $uuid,
        ]);


        $this->connections[$uuid]->close();

        unset($this->connections[$uuid]);

    }
}