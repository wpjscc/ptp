<?php

namespace Wpjscc\PTP\Local;

use React\Promise\Promise;
use Wpjscc\PTP\Proxy\AbstractConnectionLimit;
use React\EventLoop\LoopInterface;
use React\Stream\ThroughStream;
use Wpjscc\PTP\Helper;

class LocalConnection extends AbstractConnectionLimit implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    private $uri;

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

    // $response 是动态通道 201或单通道的 310 响应，里面有uri 和 uuid
    public function pipe($connection, &$buffer, $response, $config)
    {
        $connection->on('data', $fn = function ($chunk) use (&$buffer) {
            $buffer .= $chunk;
        });

        $this->getIdleConnection($config)->then(function ($localConnection) use ($connection, &$fn, &$buffer, $response, $config) {
            $connection->removeListener('data', $fn);
            $fn = null;

            $localConnection->pipe(new ThroughStream(function ($data) {
                static::getLogger()->debug('local connection response data', [
                    'lenght' => strlen($data),
                ]);
                // todo 压缩/加密
                return $data;
            }))->pipe($connection, [
                'end' => false
            ]);

            $connection->pipe(new ThroughStream(function ($buffer) use ($config) {
                static::getLogger()->debug('dynamic connection receive data', [
                    'lenght' => strlen($buffer),
                ]);
                // todo 解压/解密
                return $this->handleRequestBuffeer($buffer, $config);
            }))->pipe($localConnection, [
                'end' => false
            ]);

            $localConnection->on('close', function () use ($connection) {
                $connection->end();
            });

            $connection->on('close', function () use ($localConnection) {
                $localConnection->end();
            });

            if ($buffer) {
                // todo 解压/解密
                $localConnection->write($this->handleRequestBuffeer($buffer, $config));
                $buffer = '';
            }
        }, function ($e) use ($connection, &$fn, &$buffer) {
            $connection->removeListener('data', $fn);
            $fn = null;
            $buffer = '';
            $connection->end(implode("\r\n", [
                'HTTP/1.1 502 Bad Gateway',
                'Content-Type: text/html; charset=UTF-8',
                'Connection: close',
                "\r\n",
                "<h1>{$e->getMessage()} 1</h1>",
            ]));
        })
            ->catch(function ($e) use ($connection, &$fn, &$buffer) {
                $connection->removeListener('data', $fn);
                $fn = null;
                $buffer = '';
                $connection->end(implode("\r\n", [
                    'HTTP/1.1 502 Bad Gateway',
                    'Content-Type: text/html; charset=UTF-8',
                    'Connection: close',
                    "\r\n",
                    "<h1>{$e->getMessage()} 2</h1>",
                ]));
            });
    }

    public function createConnection($config = []): \React\Promise\PromiseInterface
    {
        $localProcol = $config['local_protocol'] ?? 'tcp';
        return (new \Wpjscc\PTP\Tunnel\Local\Tunnel($config))->getTunnel($localProcol);
    }


    protected function handleRequestBuffeer($buffer, $config)
    {
        $buffer = $this->replaceLocalHost($buffer, $this->uri, $config);
        $buffer = $this->removeXff($buffer, $config);
        $buffer = $this->removeXRealIp($buffer, $config);
        return $buffer;
    }


    protected function replaceLocalHost($buffer, $uri, $config)
    {

        if ($config['local_replace_host'] ?? false) {
            $localHostPort = Helper::getLocalHostAndPort($config);
            $buffer = preg_replace('/Host: '.$uri.'.*\r\n/', "Host: $localHostPort\r\n", $buffer);
        }

        return $buffer;
    }

    public function removeXff($buffer, $config)
    {
        if ($config['local_remove_xff'] ?? false) {
            $buffer = preg_replace('/X-Forwarded-For:.*\r\n/', '', $buffer);
        }
        return $buffer;
    }

    public function removeXRealIp($buffer, $config)
    {
        if ($config['local_remove_x_real_ip'] ?? false) {
            $buffer = preg_replace('/X-Real-IP:.*\r\n/', '', $buffer);
        }

        return $buffer;
    }
}
