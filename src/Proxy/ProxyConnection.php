<?php

namespace Wpjscc\Penetration\Proxy;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use RingCentral\Psr7;
use Wpjscc\Penetration\Helper;
use React\Stream\ThroughStream;
use Ramsey\Uuid\Uuid;

class ProxyConnection implements \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    public $max_connections;
    public $max_wait_queue;
    public $current_connections = 0;
    private $wait_timeout = 0;
    public $idle_connections;
    public $wait_queue;
    private $loop;
    private $uri;

    // 当前的连接数
    public $connections;


    public function __construct(
        $uri,
        $config = [],
        LoopInterface $loop = null
    )
    {
        $this->uri = $uri;
        $this->max_connections = $config['max_connections'] ?? 10;
        $this->max_wait_queue = $config['max_wait_queue'] ?? 50;
        $this->wait_timeout = $config['wait_timeout'] ?? 5;
        $this->wait_queue = new \SplObjectStorage;
        $this->loop = $loop ?: Loop::get();
    }

    public function pipe($userConnection, &$buffer, $request)
    {


        $userConnection->on('data', $fn =function($chunk) use (&$buffer) {
            $buffer .= $chunk;
        });
        $this->getIdleConnection()->then(function (ConnectionInterface $clientConnection) use ($userConnection, &$buffer, $fn, $request) {
            $clientConnection->tunnelConnection->once('close', $fnclose = function () use ($clientConnection, $userConnection) {
                static::getLogger()->warning("ProxyConnection::".__FUNCTION__, [
                    'class' => __CLASS__,
                    'message' => 'tunnel connection close trigger dynamic connection close',
                ]);
                $clientConnection->close();
                $userConnection->close();
            });
            $localHost = ProxyManager::$remoteTunnelConnections[$this->uri][$clientConnection->tunnelConnection]['Local-Host'];

            $proxyReplace = "";
            $proxyReplace = "\r\nHost: $localHost\r\n";

            if ($request && !$request->hasHeader('X-Forwarded-Host')) {
                $host = $request->getUri()->getHost();
                $port = $request->getUri()->getPort();
                $scheme = $request->getUri()->getScheme();
                $x_forwarded_for = '';
                $proxyReplace .= "X-Forwarded-Host: $host\r\n";
                $proxyReplace .= "X-Forwarded-Port: $port\r\n";
                $proxyReplace .= "X-Forwarded-Proto: $scheme\r\n";
                // $proxyReplace .= "x-forwarded-for: \r\n";
                $proxyReplace .= "X-Forwarded-Server: reactphp-intranet-penetration\r\n";
            }

            $userConnection->removeListener('data', $fn);
            $fn = null;
            $dynamicAddress = $clientConnection->getRemoteAddress();

            // 单通道不需要告诉客户端
            if (($clientConnection->protocol ?? '') === 'single') {
                // 单通道自己生成uuid
                $uuid = $clientConnection->uuid;
                if (!$uuid) {
                    static::getLogger()->error("ProxyConnection::".__FUNCTION__." single tunnel uuid is empty", [
                        'class' => __CLASS__,
                        'uuid' => $uuid,
                    ]);
                }
            } else {
                // 告诉客户端，通道通了
                $uuid = Uuid::uuid4()->toString();
                $clientConnection->uuid = $uuid;
                $clientConnection->write("HTTP/1.1 201 OK\r\nUuid: {$uuid}\r\n\r\n");
            }

            // 保存当前连接
            $this->connections[$uuid] = $clientConnection;

            static::getLogger()->info("dynamic connection success", [
                'class' => __CLASS__,
                'dynamicAddress' => $dynamicAddress,
                'uuid' => $uuid,
            ]);
           

            $middle = new ThroughStream(function ($data) use ($proxyReplace, $uuid) {
                if ($proxyReplace) {
                    $data = str_replace("\r\nHost: " . $this->uri . "\r\n", $proxyReplace, $data);
                }
                static::getLogger()->notice("dynamic connection send data", [
                    'class' => __CLASS__,
                    'uuid' => $uuid,
                    'length' => strlen($data),
                ]);
                return $data;
            });

            // 交换数据
            $userConnection->pipe($middle)->pipe($clientConnection);

            // udp 协议需要特殊处理
            if (isset($clientConnection->protocol) && $clientConnection->protocol == 'udp') {
                $clientConnection->pipe(new ThroughStream(function ($buffer) use ($clientConnection, $uuid) {
                    if (strpos($buffer, 'POST /close HTTP/1.1') !== false) {
                        static::getLogger()->notice("udp dynamic connection receive close request", [
                            'class' => __CLASS__,
                            'uuid' => $uuid,
                            'dynamicAddress' => $clientConnection->getRemoteAddress(),
                        ]);
                        $clientConnection->close();
                        return '';
                    }
                    static::getLogger()->notice("udp dynamic connection receive data", [
                        'uuid' => $uuid,
                        'length' => strlen($buffer),
                    ]);
                    return $buffer;
                }))->pipe($userConnection);
               
            } else {
                $clientConnection->pipe(new ThroughStream(function ($buffer) use ($uuid) {
                    static::getLogger()->notice("dynamic connection receive data", [
                        'uuid' => $uuid,
                        'length' => strlen($buffer),
                    ]);
                    // file_put_contents('/root/Code/reactphp-intranet-penetration/server.txt', $buffer, FILE_APPEND);
                    return $buffer;
                }))->pipe($userConnection);
            }

            // pipe 关闭仅用于end事件  https://reactphp.org/stream/#pipe
            // close 要主动关闭
            $clientConnection->on('close', function () use ($dynamicAddress, $userConnection, $uuid) {
                unset($this->connections[$uuid]);
                static::getLogger()->notice("dynamic connection close", [
                    'class' => __CLASS__,
                    'dynamicAddress' => $dynamicAddress,
                    'uuid' => $uuid,
                ]);
                $userConnection->close();
            });

            $clientConnection->on('end', function () use ($uuid) {
                static::getLogger()->notice("dynamic connection end", [
                    'class' => __CLASS__,
                    'uuid' => $uuid,
                ]);
            });

            $userConnection->on('end', function () use ($clientConnection, $uuid) {

                static::getLogger()->notice("user connection end", [
                    'class' => __CLASS__,
                    'uuid' => $uuid,
                ]);

                // udp 协议需要特殊处理
                if (isset($clientConnection->protocol) && $clientConnection->protocol == 'udp') {
                    static::getLogger()->notice("udp dynamic connection end and try send close request", [
                        'class' => __CLASS__,
                        'uuid' => $uuid,
                    ]);
                    $clientConnection->write("POST /close HTTP/1.1\r\n\r\n");
                }
            });

            $userConnection->on('close', function () use ($clientConnection, &$fnclose, $uuid) {
                unset($this->connections[$uuid]);
                static::getLogger()->notice("user connection close", [
                    'class' => __CLASS__,
                    'uuid' => $uuid,
                ]);
                $clientConnection->close();
                $clientConnection->tunnelConnection->removeListener('close', $fnclose);
                $fnclose = null;
            });

            $middle->on('end', function () {
                echo 'middleware connection end' . "\n";
            });

            if ($buffer) {
                $buffer = str_replace("\r\nHost: " . $this->uri . "\r\n", $proxyReplace, $buffer);
                $clientConnection->write($buffer);
                $buffer = '';
            }

        }, function ($e) use ($userConnection, &$buffer) {
            $buffer = '';
            static::getLogger()->error($e->getMessage().'-1', [
                'class' => __CLASS__,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $message = $e->getMessage();
            $length = strlen($e->getMessage());
            $now = date('Y-m-d H:i:s');
            $userConnection->write("HTTP/1.1 502 Bad Gateway\r\nContent-Type: text/plain; charset=utf-8\r\nDate: {$now}\r\nContent-Length: {$length}\r\n\r\n".$message);
            $userConnection->end();
        })->otherwise(function ($error) use ($userConnection, &$buffer) {
            $buffer = '';
            static::getLogger()->error($error->getMessage().'-2', [
                'class' => __CLASS__,
                'file' => $error->getFile(),
            ]);
            $message = $error->getMessage();
            $length = strlen($error->getMessage());
            $now = date('Y-m-d H:i:s');
            $userConnection->write("HTTP/1.1 500 Error\r\nContent-Type: text/plain; charset=utf-8\r\nDate: {$now}\r\nContent-Length: {$length}\r\n\r\n".$message);
            $userConnection->end();
        });

    }

    public function getIdleConnection()
    {

        if ($this->current_connections < $this->max_connections) {
            $this->current_connections++;
            // todo 链接关闭
            return \React\Promise\Timer\timeout(ProxyManager::createRemoteDynamicConnection($this->uri)->then(function (ConnectionInterface $connection) {
                
                $connection->on('close', function () use ($connection) {
                    $this->current_connections--;
                });
                return $connection;
            }), $this->wait_timeout, $this->loop)->then(null, function ($e) {
                $this->current_connections--;
                if ($e instanceof TimeoutException) {
                    throw new \RuntimeException(
                        'wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)'. 'and wait queue '.$this->wait_queue->count().' count',
                        \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                    );
                }
                throw $e;

            });
        }

        if ($this->max_wait_queue && $this->wait_queue->count() >= $this->max_wait_queue) {
            return \React\Promise\reject(new \Exception("over max_wait_queue: ". $this->max_wait_queue.'-current quueue:'.$this->wait_queue->count()));
        }

        $deferred = new Deferred();
        $this->wait_queue->attach($deferred);

        if (!$this->wait_timeout) {
            return $deferred->promise();
        }
        
        $that = $this;

        return \React\Promise\Timer\timeout($deferred->promise(), $this->wait_timeout, $this->loop)->then(null, function ($e) use ($that, $deferred) {
            
            $that->wait_queue->detach($deferred);

            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)'. 'and wait queue '.$that->wait_queue->count().' count',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                );
            }
            throw $e;
        });
    }
}