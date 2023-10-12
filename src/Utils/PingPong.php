<?php

namespace Wpjscc\Penetration\Utils;

use React\Promise\Timer\TimeoutException;
use React\Promise\Deferred;

class PingPong 
{

    public static function ping($connection)
    {
        $connection->write("HTTP/1.1 300 OK\r\n\r\n");
    } 

    public static function pong($connection, $timer = 3)
    {

        $deferred = new Deferred();
        $timer = \React\EventLoop\Loop::addTimer(min(1.5, $timer), function () use ($connection) {
            static::ping($connection);
        });
        $connection->on('data', $fn = function ($buffer) use ($deferred, $connection, &$fn, $timer) {
            if (strpos($buffer, "HTTP/1.1 301 OK\r\n") !== false) {
                $connection->removeListener('data', $fn);
                $fn = null;
                \React\EventLoop\Loop::cancelTimer($timer);
                $deferred->resolve(null);
            }
        });

        \React\Promise\Timer\timeout($deferred->promise(), 3)->then(null, function ($e) use ($connection, $fn, $deferred) {
            $connection->removeListener('data', $fn);
            if ($e instanceof TimeoutException) {
                $e =  new \RuntimeException(
                    'ping wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                );
            }
            $deferred->reject($e);
        });
        return $deferred->promise();
    }

    public static function pingPong($connection, $address, $header = '')
    {

        $connection->on('data', function ($buffer) use ($connection, $address, $header) {
            if (strpos($buffer, "HTTP/1.1 300 OK\r\n\r\n") !== false) {
                if ($header) {
                    $connection->write("HTTP/1.1 301 OK\r\n".$header);
                } else {
                    $connection->write("HTTP/1.1 301 OK\r\n\r\n");
                }
            }
        });

        $timer = \React\EventLoop\Loop::addPeriodicTimer(10, function () use ($connection, $address) {
            echo ("======> start ping " . $address . "\n");
            static::ping($connection);
            static::pong($connection)->then(function () use ($address) {
                echo ("======> $address pong success\n\n");
            }, function ($e) use ($connection, $address) {
                echo ("======> $address pong fail ");
                echo $e->getMessage() . PHP_EOL;
                $connection->close();
            });
        });
        $connection->on('close', function () use ($timer) {
            \React\EventLoop\Loop::cancelTimer($timer);
        });
        return $timer;
    }

    public static function allPingPong($connections, $timer = 2)
    {
        $pongs = [];

        foreach ($connections as $connection) {
            static::ping($connection);
            $pongs[] = static::pong($connection, $timer);
        }

        return \React\Promise\all($pongs);
    }
}