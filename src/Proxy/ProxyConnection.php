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

class ProxyConnection
{
    private $max_connections;
    private $max_wait_queue;
    private $current_connections = 0;
    private $wait_timeout = 0;
    private $idle_connections;
    private $wait_queue;
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
       
        $this->getIdleConnection($this->uri)->then(function (ConnectionInterface $clientConnection) use ($userConnection, &$buffer) {
            
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

            $userConnection->once('close', function () use ($clientConnection) {
                $this->releaseConnection($clientConnection);
            });

            if ($buffer) {
                $clientConnection->write($buffer);
                $buffer = '';
            }

        }, function ($e) use ($userConnection) {
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
            return \React\Promise\resolve(ClientManager::createConnection($this->uri));
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
            $this->current_connections--;
            $this->idle_connections->detach($connection);
            return;
        }

        if ($this->wait_queue->count()>0) {
            $deferred = $this->wait_queue->current();
            $deferred->resolve($connection);
            $this->wait_queue->detach($deferred);
            return;
        }

        $this->idle_connections->attach($connection);
    }

    public function addIdleConnection($connection)
    {
        $this->idle_connections->attach($connection);
        $this->current_connections++;

        $connection->on('close', function () use ($connection) {
            $this->current_connections--;
            $this->idle_connections->detach($connection);
        });
    }
}