<?php

namespace Wpjscc\PTP\Proxy;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Stream\ThroughStream;
use Wpjscc\PTP\Bandwidth\AsyncThroughStream;
use Wpjscc\PTP\Bandwidth\BufferBandwidthManager;
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
            $config['max_connections'] ?? 100,
            $config['max_wait_queue'] ?? 500,
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

            BufferBandwidthManager::instance($this->uri)->setBandwidth(
                1024 * 1024 * 1024 * (ProxyManager::$uriToInfo[$this->uri]['bandwidth_limit']['max_bandwidth'] ?? 5),
                1024 * 1024 * 1024 * (ProxyManager::$uriToInfo[$this->uri]['bandwidth_limit']['bandwidth'] ?? 1),
            );
            $userAsyncThroughStream = new AsyncThroughStream(function ($data, $stream) use ($uuid) {
                static::getLogger()->debug("user connection receive data", [
                    'class' => __CLASS__,
                    'uuid' => $uuid,
                    'length' => strlen($data),
                ]);
                // todo 压缩/加密
                // return $data;
                // 带宽限制
                return BufferBandwidthManager::instance($this->uri)->addBuffer($stream, $data);
            });
            // 交换数据
            $userConnection->pipe($userAsyncThroughStream, [
                'end' => false
            ])->pipe($clientConnection, [
                'end' => false
            ]);

            $clientAsyncThroughStream = new AsyncThroughStream(function ($data, $stream) use ($uuid) {
                static::getLogger()->debug("dynamic connection receive data", [
                    'class' => __CLASS__,
                    'uuid' => $uuid,
                    'length' => strlen($data),
                    'stream_id' => spl_object_id($stream),
                ]);
                // todo 解压/解密
                // return $data;
                // 带宽限制
                return BufferBandwidthManager::instance($this->uri)->addBuffer($stream, $data);
            });
        
            $clientConnection->pipe($clientAsyncThroughStream, [
                'end' => false
            ])->pipe($userConnection, [
                'end' => false
            ]);


            // pipe 关闭仅用于end事件  https://reactphp.org/stream/#pipe
            // close 要主动关闭
            $clientConnection->on('close', function () use ($userConnection, $uuid, $clientAsyncThroughStream) {
                static::getLogger()->notice("dynamic connection close", [
                    'class' => __CLASS__,
                    'uuid' => $uuid,
                    'stream_id' => spl_object_id($clientAsyncThroughStream),
                ]);
                // 通道关闭，这时
                BufferBandwidthManager::instance($this->uri)->setParentStreamClose(spl_object_id($clientAsyncThroughStream));

            });

            $clientAsyncThroughStream->on('close', function () use ($userConnection, $uuid) {
                static::getLogger()->notice("clientAsyncThroughStream close", [
                    'class' => __CLASS__,
                    'uuid' => $uuid,
                ]);
                unset($this->connections[$uuid]);
                $userConnection->end();
            });

            $userConnection->on('close', function () use ($clientConnection, &$fnclose, $uuid, $userAsyncThroughStream, $clientAsyncThroughStream) {
                unset($this->connections[$uuid]);
                static::getLogger()->notice("user connection close", [
                    'class' => __CLASS__,
                    'uuid' => $uuid,
                ]);
                $clientConnection->end();
                $clientConnection->tunnelConnection->removeListener('close', $fnclose);
                $fnclose = null;

                $userAsyncThroughStream->end();
                $clientAsyncThroughStream->end();

            });

            if ($buffer) {
                // todo 压缩/加密
                // $clientConnection->write($buffer);
                $userAsyncThroughStream->write($buffer);

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
