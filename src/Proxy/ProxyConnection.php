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

class ProxyConnection
{
    public $max_connections;
    public $max_wait_queue;
    public $current_connections = 0;
    private $wait_timeout = 0;
    public $idle_connections;
    public $wait_queue;
    private $loop;
    private $uri;


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
                echo 'tunnel connection close trigger dynamic connection close' . "\n";
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
            echo "dynamic connection success ".$dynamicAddress."\n";
            $headers = [
                'HTTP/1.1 201 OK',
                'Server: ReactPHP/1',
            ];
            // 告诉clientConnection 开始连接了
            $clientConnection->write(implode("\r\n", $headers) . "\r\n\r\n");

            $middle = new ThroughStream(function ($data) use ($proxyReplace) {
                if ($proxyReplace) {
                    $data = str_replace("\r\nHost: " . $this->uri . "\r\n", $proxyReplace, $data);
                }
                return $data;
            });

            // 交换数据
            $userConnection->pipe($middle)->pipe($clientConnection);

            if (isset($clientConnection->protocol) && $clientConnection->protocol == 'udp') {
                $clientConnection->pipe(new ThroughStream(function ($buffer) use ($clientConnection) {
                    // var_dump($buffer);
                    if (strpos($buffer, 'POST /close HTTP/1.1') !== false) {
                        echo 'udp dynamic connection receive close request' . "\n";
                        $clientConnection->close();
                        return '';
                    }
                    return $buffer;
                }))->pipe($userConnection);
               
            } else {
                $clientConnection->pipe($userConnection);
            }

            // pipe 关闭仅用于end事件  https://reactphp.org/stream/#pipe
            // close 要主动关闭
            $clientConnection->on('close', function () use ($dynamicAddress, $userConnection) {
                $userConnection->close();
                echo 'dynamic connection close ' .$dynamicAddress. "\n";
            });

            $clientConnection->on('end', function () {
                echo 'dynamic connection end' . "\n";
            });

            $userConnection->on('end', function () use ($clientConnection) {
                echo 'user connection end' . "\n";
                if (isset($clientConnection->protocol) && $clientConnection->protocol == 'udp') {
                    echo 'udp dynamic connection end and try send close request' . "\n";
                    $clientConnection->write("POST /close HTTP/1.1\r\n\r\n");
                }
            });

            $userConnection->on('close', function () use ($clientConnection, &$fnclose) {
                echo 'user connection close' . "\n";
                
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
            echo $e->getMessage()."-1\n";
            $userConnection->write("http/1.1 500 ".$e->getMessage()." Error\r\n\r\n".$e->getMessage());
            $userConnection->end();
        })->otherwise(function ($error) use ($userConnection, &$buffer) {
            $buffer = '';
            echo $error->getMessage()."-2\n";
            $userConnection->write("http/1.1 500 Internal Server Error\r\n\r\n".$error->getMessage());
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