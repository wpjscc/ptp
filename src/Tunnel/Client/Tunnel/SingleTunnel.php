<?php

namespace Wpjscc\Penetration\Tunnel\Client\Tunnel;

use Evenement\EventEmitter;
use React\Socket\ConnectorInterface;
use RingCentral\Psr7;
use Ramsey\Uuid\Uuid;
use React\Stream\ThroughStream;
use Wpjscc\Penetration\CompositeConnectionStream;

class SingleTunnel extends EventEmitter
{

    private $connections = array();
    private $connection;

    private $buffer = '';

    public function __construct()
    {
        
    }

    public function overConnection($connection)
    {
        $this->connection = $connection;

        $this->connection->on('data', [$this, 'parseBuffer']);
        $this->connection->on('close', [$this, 'close']);
    }


    
   
    protected function parseBuffer($buffer)
    {

        if ($buffer === '') {
            return;
        }

        echo 'single tunnel parseBuffer'."\n";


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
                echo $e->getFile()."\n";
                echo $e->getLine()."\n";
                echo $e->getMessage();
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

            // 服务端ping
            elseif ($response->getStatusCode() === 300) {
                echo 'server ping'."\n";
                $this->connection->write("HTTP/1.1 301 OK\r\n\r\n");
            }
            
            else {
                // ignore other response code
                echo 'ignore other response code'.$response->getStatusCode()."\n";
            }

            // 继续解析
            $this->parseBuffer('');
        }
    }

    protected function createConnection($response)
    {

        $uuid = $response->getHeaderLine('Uuid');
        $read = new ThroughStream;
        $write = new ThroughStream;
        $contection = new CompositeConnectionStream($read, $write, null,  'single');

        $write->on('data', function ($data) use ($uuid)  {
            echo "single tunnel send data-{$uuid}\n" ."\n";
            $data = base64_encode($data);
            $this->connection->write("HTTP/1.1 311 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
        });

        $read->on('close', function () use ($uuid) {
            echo "single tunnel close-{$uuid}\n" ."\n";
            $this->connection->write("HTTP/1.1 312 OK\r\nUuid: {$uuid}\r\n\r\n");
            unset($this->connections[$uuid]);
        });

        $this->connections[$uuid] = $contection;
        $this->emit('connection', array($contection));
        $this->connection->write("HTTP/1.1 310 OK\r\nUuid: {$uuid}\r\n\r\n");

    }

    protected function handleData($response)
    {
        $uuid = $response->getHeaderLine('Uuid');

        if (!Uuid::isValid($uuid)) {
            return;
            // ignore
        }

        if (!isset($this->connections[$uuid])) {
            return;
            // ignore
        }

        $data = base64_decode($response->getHeaderLine('Data'));

        echo "single tunnel receive data-{$uuid}\n" ."\n";


        $this->connections[$uuid]->emit('data', array($data));
    }

    protected function handleClose($response)
    {
        $uuid = $response->getHeaderLine('Uuid');

        if (!Uuid::isValid($uuid)) {
            return;
            // ignore
        }

        if (!isset($this->connections[$uuid])) {
            return;
            // ignore
        }

        $this->connections[$uuid]->close();

    }

    public function close()
    {
        foreach ($this->connections as $contection) {
            $contection->close();
        }
    }
}