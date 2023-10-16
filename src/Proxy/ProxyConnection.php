<?php

namespace Wpjscc\PTP\Proxy;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Stream\ThroughStream;
use Ramsey\Uuid\Uuid;
use React\Promise\Promise;

class ProxyConnection extends AbstractConnectionLimit implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    private $uri;

    // 当前的连接数
    public $connections = [];


    public function __construct(
        $uri,
        $config = [],
        LoopInterface $loop = null
    ) {
        parent::__construct(
            $config['max_connections'] ?? 10,
            $config['max_wait_queue'] ?? 50,
            $config['wait_timeout'] ?? 5,
            $loop
        );
        $this->uri = $uri;
    }

    public function pipe($userConnection, &$buffer, $request)
    {
        $userConnection->on('data', $fn = function ($chunk) use (&$buffer) {
            $buffer .= $chunk;
        });
        $this->getIdleConnection()->then(function (ConnectionInterface $clientConnection) use ($userConnection, &$buffer, $fn, $request) {
            $clientConnection->tunnelConnection->once('close', $fnclose = function () use ($clientConnection, $userConnection) {
                static::getLogger()->warning("ProxyConnection::" . __FUNCTION__, [
                    'class' => __CLASS__,
                    'message' => 'tunnel connection close trigger dynamic connection close',
                ]);
                $clientConnection->end();
                $userConnection->end();
            });
            $userConnection->removeListener('data', $fn);
            $fn = null;

            // 单通道不需要告诉客户端
            if (($clientConnection->protocol ?? '') === 'single') {
                // 单通道自己生成uuid
                $uuid = $clientConnection->uuid;
                if (!$uuid) {
                    static::getLogger()->error("ProxyConnection::" . __FUNCTION__ . " single tunnel uuid is empty", [
                        'class' => __CLASS__,
                        'uuid' => $uuid,
                    ]);
                }
            } else {
                // 告诉客户端，通道通了
                $uuid = Uuid::uuid4()->toString();
                $clientConnection->uuid = $uuid;
                $clientConnection->write("HTTP/1.1 201 OK\r\nUuid: {$uuid}\r\nUri: {$this->uri}\r\n\r\n");
            }

            // 保存当前连接
            $this->connections[$uuid] = $clientConnection;

            static::getLogger()->debug("dynamic connection success", [
                'class' => __CLASS__,
                'uuid' => $uuid,
            ]);


            // 交换数据
            $userConnection->pipe(new ThroughStream(function ($data){
                // todo 压缩/加密

                return $data;
            }))->pipe($clientConnection, [
                'end' => false
            ]);
        
            $clientConnection->pipe(new ThroughStream(function ($buffer) use ($uuid) {
                static::getLogger()->debug("dynamic connection receive data", [
                    'uuid' => $uuid,
                    'length' => strlen($buffer),
                ]);

                // todo 解压/解密

                return $buffer;
            }))->pipe($userConnection, [
                'end' => false
            ]);


            // pipe 关闭仅用于end事件  https://reactphp.org/stream/#pipe
            // close 要主动关闭
            $clientConnection->on('close', function () use ($userConnection, $uuid) {
                unset($this->connections[$uuid]);
                $userConnection->end();
            });

            $userConnection->on('close', function () use ($clientConnection, &$fnclose, $uuid) {
                unset($this->connections[$uuid]);
                static::getLogger()->debug("user connection close", [
                    'class' => __CLASS__,
                    'uuid' => $uuid,
                ]);
                $clientConnection->end();
                $clientConnection->tunnelConnection->removeListener('close', $fnclose);
                $fnclose = null;
            });

            if ($buffer) {
                // todo 压缩/加密
                $clientConnection->write($buffer);
                $buffer = '';
            }
        }, function ($e) use ($userConnection, &$buffer) {
            $buffer = '';
            static::getLogger()->error($e->getMessage() . '-1', [
                'class' => __CLASS__,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $message = $e->getMessage();
            $length = strlen($e->getMessage());
            $now = date('Y-m-d H:i:s');
            $userConnection->write("HTTP/1.1 502 Bad Gateway\r\nContent-Type: text/plain; charset=utf-8\r\nDate: {$now}\r\nContent-Length: {$length}\r\n\r\n" . $message);
            $userConnection->end();
        })
        ->catch(function ($error) use ($userConnection, &$buffer) {
            $buffer = '';
            static::getLogger()->error($error->getMessage() . '-2', [
                'class' => __CLASS__,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ]);
            $message = $error->getMessage();
            $now = date('Y-m-d H:i:s');
            $userConnection->write("HTTP/1.1 500 Error\r\nContent-Type: text/plain; charset=utf-8\r\nDate: {$now}\r\n\r\n" . $message);
            $userConnection->end();
        });
    }

    public function createConnection($config = []): \React\Promise\PromiseInterface
    {
        return ProxyManager::createRemoteDynamicConnection($this->uri);
    }
}
