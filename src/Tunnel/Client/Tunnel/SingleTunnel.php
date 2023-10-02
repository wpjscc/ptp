<?php

namespace Wpjscc\Penetration\Tunnel\Client\Tunnel;

use Evenement\EventEmitter;
use React\Socket\ConnectorInterface;
use RingCentral\Psr7;
use Ramsey\Uuid\Uuid;
use React\Stream\ThroughStream;
use Wpjscc\Penetration\CompositeConnectionStream;
use Wpjscc\Penetration\Helper;

class SingleTunnel extends EventEmitter implements \Wpjscc\Penetration\Log\LogManagerInterface, \Wpjscc\Penetration\Tunnel\SingleTunnelInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

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

        $this->buffer .= $buffer;


        static::getLogger()->info("SingleTunnel::".__FUNCTION__, [
            'class' => __CLASS__,
            'length' => strlen($buffer),
        ]);

        $pos = strpos($this->buffer, "\r\n\r\n");
        if ($pos !== false) {
            $httpPos = strpos($this->buffer, "HTTP/1.1");
            if ($httpPos === false) {
                $httpPos = 0;
            }
            try {
                $response = Psr7\parse_response(substr($this->buffer, $httpPos, $pos - $httpPos));
            } catch (\Exception $e) {
                static::getLogger()->error($e->getMessage(), [
                    'current_file' => __FILE__,
                    'current_line' => __LINE__,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'buffer' => substr($this->buffer, $httpPos, $pos - $httpPos)
                ]);

                // 忽视掉这个buffer
                $this->buffer = substr($this->buffer, $pos + 4);
                return;
            }

            $this->buffer = substr($this->buffer, $pos + 4);

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
                static::getLogger()->info('server ping', [
                    'code' => $response->getStatusCode(),
                ]);
                $this->connection->write("HTTP/1.1 301 OK\r\n\r\n");
            } else {
                // ignore other response code
                static::getLogger()->warning('ignore other response code', [
                    'code' => $response->getStatusCode(),
                ]);
            }

            // 继续解析
            if ($this->buffer) {
                $this->parseBuffer(null);
            }
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
                    // \React\EventLoop\Loop::addTimer(0.001 * $k, function () use ($uuid, $data) {
                        $this->connection->write("HTTP/1.1 311 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
                    // });
                }
                return;
            } else {
                static::getLogger()->debug('single tunnel send data[no chunk]', [
                    'uuid' => $uuid,
                    'length' => $length,
                ]);
            }
            
            
            $data = base64_encode($data);
            $this->connection->write("HTTP/1.1 311 OK\r\nUuid: {$uuid}\r\nData: {$data}\r\n\r\n");
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
        // var_dump('single tunnel receive data', $data);

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
