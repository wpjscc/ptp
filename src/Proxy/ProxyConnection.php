<?php

namespace Wpjscc\Penetration\Proxy;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectorInterface;
use React\Socket\ConnectionInterface;

use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Client\ProxyClientManager;
use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use RingCentral\Psr7;

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
        LoopInterface $loop = null,
        ConnectorInterface $connector = null
    )
    {
        $this->uri = $uri;
        $this->max_connections = $config['max_connections'] ?? 10;
        $this->max_wait_queue = $config['max_wait_queue'] ?? 50;
        $this->wait_timeout = $config['wait_timeout'] ?? 5;
        $this->wait_queue = new \SplObjectStorage;
        $this->idle_connections = new \SplObjectStorage;
        $this->loop = $loop ?: Loop::get();
    }

    public function pipe($userConnection, &$buffer)
    {

        $userConnection->on('data', $fn =function($chunk) use (&$buffer) {
            $buffer .= $chunk;
        });
        $this->getIdleConnection($this->uri)->then(function (ConnectionInterface $clientConnection) use ($userConnection, &$buffer, $fn) {
            ProxyManager::$userConnections[$clientConnection->getRemoteAddress()] = $userConnection;
            $userConnection->removeListener('data', $fn);
            $fn = null;
            echo "get dynamic connection success \n";
            $headers = [
                'HTTP/1.1 201 OK',
                'Server: ReactPHP/1'
            ];

            // 告诉clientConnection 开始连接了
            $clientConnection->write(implode("\r\n", $headers)."\r\n\r\n");

            $userConnection->pipe($clientConnection, [
                'end' => false
            ]);


            $clientConnection->pipe($userConnection);


            $userConnection->on('close', function () use ($clientConnection) {
                // 还没有被关闭，发送一个消息主动关闭
                if (isset(ProxyManager::$userConnections[$clientConnection->getRemoteAddress()])) {
                    unset(ProxyManager::$userConnections[$clientConnection->getRemoteAddress()]);
                    echo "user connection close \n";
                    $headers = [
                        'HTTP/1.1 204 No Content',
                        'Server: ReactPHP/1',
                        'Remote-Uniqid: '.$clientConnection->getRemoteAddress(),
                    ];
                    // 告诉clientConnection 关闭连接了(通过tunnelConnection 发送)
                    $clientConnection->tunnelConnection->write(implode("\r\n", $headers)."\r\n\r\n");
                }
                $this->releaseConnection($clientConnection);
            });

            if ($buffer) {
                $clientConnection->write($buffer);
                $buffer = '';
            }
            $clientConnection->resume();

        }, function ($e) use ($userConnection) {
            echo $e->getMessage()."\n";
            $userConnection->write($e->getMessage());
            $userConnection->close();
        });

    }

    public function getIdleConnection()
    {
        if ($this->idle_connections->count()>0) {
            $connection = $this->idle_connections->current();
            $this->idle_connections->detach($connection);
            return \React\Promise\resolve($connection);
        }

        if ($this->current_connections < $this->max_connections) {
            $this->current_connections++;
            // todo 链接关闭
            return \React\Promise\resolve(ClientManager::createRemoteDynamicConnection($this->uri));
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

    public function releaseConnection(ConnectionInterface $connection)
    {

        if (!$connection->isWritable()) {
            echo "connection is not writable \n";
            $this->current_connections--;
            $this->idle_connections->detach($connection);
            return;
        }

        if ($this->wait_queue->count()>0) {
            echo "wait queue has connection ".$this->wait_queue->count()."\n";
            $deferred = $this->wait_queue->current();
            $this->wait_queue->detach($deferred);
            $deferred->resolve($connection);

            return;
        }
        echo "release idle connection \n";
        $this->idle_connections->attach($connection);
    }
}