<?php 

namespace Wpjscc\PTP\Proxy;

use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use React\Socket\ConnectionInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;

abstract class AbstractConnectionLimit
{
    protected int $max_connections;
    protected int $max_wait_queue;
    protected int $current_connections = 0;
    private int $wait_timeout = 0;
    protected \SplObjectStorage $wait_queue;

    private $loop;


    public function __construct($max_connections, $max_wait_queue, $wait_timeout = 0, LoopInterface $loop = null)
    {
        $this->max_connections = $max_connections;
        $this->max_wait_queue = $max_wait_queue;
        $this->wait_timeout = $wait_timeout;
        $this->wait_queue = new \SplObjectStorage();
        $this->loop = $loop ?: Loop::get();
    }

    public function getMaxConnectins(): int
    {
        return $this->max_connections;
    }

    public function getMaxWaitQueue(): int
    {
        return $this->max_wait_queue;
    }

    public function getCurrentConnections(): int
    {
        return $this->current_connections;
    }

    public function getWaitTimeout(): int
    {
        return $this->wait_timeout;
    }

    public function getWaitQueueCount(): int
    {
        return $this->wait_queue->count();
    }

    public function getIdleConnection($config = [])
    {

        if ($this->current_connections < $this->max_connections) {
            $this->current_connections++;
            // todo 链接关闭
            return \React\Promise\Timer\timeout($this->createConnection($config)->then(function (ConnectionInterface $connection) {
                
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



    abstract public function createConnection($config = []): \React\Promise\PromiseInterface;

}