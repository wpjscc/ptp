<?php

namespace Wpjscc\PTP\Server;

use React\Socket\ConnectionInterface;
use Wpjscc\PTP\Tunnel\Server\Tunnel\TcpTunnel;

class TcpProxy
{
    protected $ip = '10.8.0.1';
    protected $port;
    protected $proxyHost;
    protected $proxyPort;

    public function __construct($ip, $port, $proxyHost, $proxyPort)
    {

        if ($ip) {
            $this->ip = $ip;
        }

        if (!$port) {
            throw new \Exception("port is required");
        }

        $this->port = $port;

        if (!$proxyHost) {
            throw new \Exception("proxyHost is required");
        }

        $this->proxyHost = $proxyHost;

        if (!$proxyPort) {
            throw new \Exception("proxyPort is required");
        }

        $this->proxyPort = $proxyPort;
    }

    public function run()
    {

        $tunnel = new TcpTunnel($this->ip . ':' . $this->port);

        $tunnel->on('connection', function (ConnectionInterface $proxyConnection) {
            echo 'tcp proxy : ' . $proxyConnection->getLocalAddress() . ' is connected' . "\n";
            $buffer = '';
            $proxyConnection->on('data', $fn = function ($data) use (&$buffer) {
                $buffer .= $data;
            });

            (new \React\Socket\Connector(array(
                'timeout' => 3.0,
                // 'tcp' => new Clue\React\HttpProxy\ProxyConnector('192.168.43.1:8234'), //可以做个跳板(http proxy)
                // 'tcp' => new Clue\React\Socks\Client('192.168.43.1:8235'), // 可以做个跳板(socket proxy),
                // 'tcp' => new Clue\React\SshProxy\SshProcessConnector('user@ip'), //可以做个跳板(ssh proxy)
                // 'dns' => false,
            )))
                ->connect("tcp://" . $this->proxyHost . ":" . $this->proxyPort)
                ->then(function (\React\Socket\ConnectionInterface $connection) use ($proxyConnection, $fn, &$buffer) {
                    print($connection->getLocalAddress() . "\n");
                    print($connection->getRemoteAddress() . "\n");

                    $proxyConnection->removeListener('data', $fn);
                    $fn = null;
                    $proxyConnection->pipe($connection);
                    $connection->pipe($proxyConnection);

                    if ($buffer) {
                        $connection->write($buffer);
                        $buffer = '';
                    }
                }, function (\Exception $e) use ($proxyConnection) {
                    $proxyConnection->write("HTTP/1.1 502 Bad Gateway\r\n\r\n" . $e->getMessage());
                    $proxyConnection->end();
                });
        });

        echo "Tcp Proxy Server is running at {$this->port}...\n";

        return $tunnel;
    }
}
