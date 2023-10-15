<?php

namespace Wpjscc\PTP\Client;


use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Config;
use Wpjscc\PTP\Tunnel\Client\Tunnel;
use Wpjscc\PTP\Utils\ParseBuffer;
use Wpjscc\PTP\Tunnel\Client\Tunnel\SingleTunnel;
use Wpjscc\PTP\Utils\PingPong;
use Evenement\EventEmitter;

class Client extends EventEmitter implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;


    protected $key;
    protected $config;
    protected $close;

    public function __construct($key)
    {
        $this->key = $key;
        $this->config = Config::getClientConfigByKey($key);
        var_dump($this->config);
    }

    public function run()
    {

        $protocol = $this->config['tunnel_protocol'];
        static::getLogger()->debug('start create tunnel connection');

        (new Tunnel($this->config))->getTunnel($protocol)->then(function ($connection) {
            static::getLogger()->debug('Connection established:', [
                'local_address' => $connection->getLocalAddress(),
                'remote_address' => $connection->getRemoteAddress(),
            ]);

            static::getLogger()->debug('send create tunnel request', [
                'request' => $this->getTunnelHeader(),
                'protocol' => $this->config['tunnel_protocol']
            ]);
            $connection->write(implode("\r\n", $this->getTunnelHeader()));

            $parseBuffer = new ParseBuffer();
            $parseBuffer->setConnection($connection);
            $parseBuffer->on('response', [$this, 'handleTunnelResponse']);

            $connection->on('data', [$parseBuffer, 'handleBuffer']);

            $connection->on('close', function () {
                static::getLogger()->warning('Connection closed', [
                    'uuid' => $this->config['uuid'] ?? '',
                ]);
                $this->tryAgain();
            });
        }, function ($e) {
            static::getLogger()->error($e->getMessage(), [
                'class' => __CLASS__,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->tryAgain();
        })->otherwise(function ($e) {
            static::getLogger()->error($e->getMessage(), [
                'class' => __CLASS__,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->tryAgain();
        });
    }

    protected function getTunnelHeader()
    {
        return [
            'GET /client HTTP/1.1',
            'Host: ' . $this->config['tunnel_host'],
            'User-Agent: ReactPHP',
            'X-Is-Ptp: 1',
            'Tunnel: 1',
            'Secret-Key: ' . ($this->config['secret_key'] ?? ''),
            'Authorization: ' . ($this->config['token'] ?? ''),
            'Local-Host: ' . $this->config['local_host'] . (($this->config['local_port'] ?? '') ? (':' . $this->config['local_port']) : ''),
            'Local-Protocol: ' . $this->config['local_protocol'],
            'Local-Replace-Host: ' . ($this->config['local_replace_host'] ?? 0),
            'Domain: ' . $this->config['domain'],
            'Single-Tunnel: ' . ($this->config['single_tunnel'] ?? 0),
            'Is-Private: ' . ($this->config['is_private'] ?? 0),
            'Http-User: ' . ($this->config['http_user'] ?? ''),
            'Http-Pwd: ' . ($this->config['http_pwd'] ?? ''),
            "\r\n"
        ];
    }

    public function getDynamicTunnelHeader()
    {
        return [
            'GET /client HTTP/1.1',
            'Host: ' . $$this->config['tunnel_host'],
            'X-Is-Ptp: 1',
            'User-Agent: ReactPHP',
            'Secret-Key: ' . ($this->config['secret_key'] ?? ''),
            'Authorization: ' . ($this->config['token'] ?? ''),
            'Domain: ' . $this->config['domain'],
            'Uuid: ' . $this->config['uuid'],
            "\r\n"
        ];
    }

    protected function tryAgain()
    {
        if ($this->close) {
            \React\EventLoop\Loop::get()->addTimer(3, function () {
                $this->run();
            });
        }

    }

    public function handleTunnelResponse($response, $parseBuffer)
    {
        $connection = $parseBuffer->getConnection();
        // 服务端返回成功
        if ($response->getStatusCode() === 200) {
            $this->addTunnelConnection($connection, $response);
        }
        // 请求创建代理连接
        elseif ($response->getStatusCode() === 201) {
            $this->createDynamicTunnelConnections($connection);
        }
        // 服务端ping
        elseif ($response->getStatusCode() === 300) {
            static::getLogger()->debug('server ping', [
                'class' => __CLASS__,
            ]);
            // $connection->write("HTTP/1.1 301 OK\r\n\r\n");
        }
        // 服务端pong
        elseif ($response->getStatusCode() === 301) {
            static::getLogger()->debug('server pong', [
                'class' => __CLASS__,
            ]);
        } else if ($response->getStatusCode() === 401) {
            static::getLogger()->error("client is Unauthorized", [
                'class' => __CLASS__,
                'status_code' => $response->getStatusCode(),
                'reason_phrase' => $response->getReasonPhrase(),
            ]);
        } else {
            static::getLogger()->warning("ignore status_code", [
                'class' => __CLASS__,
                'status_code' => $response->getStatusCode(),
                'reason_phrase' => $response->getReasonPhrase(),
            ]);
        }
    }

    public function addTunnelConnection($connection, $response)
    {

        $uri = $response->getHeaderLine('Uri');
        $uuid = $response->getHeaderLine('Uuid');

        $this->config['uri'] = $uri;
        $this->config['uuid'] = $uuid;

        static::getLogger()->debug('local tunnel success ', [
            'class' => __CLASS__,
            'uri' => $uri,
            'uuid' => $uuid,
            'response' => Helper::toString($response)
        ]);

        ClientManager::addTunnelConnection($uri, $connection, $this->key);

        $connection->on('close', function () use ($uri, $connection) {
            static::getLogger()->debug('local tunnel connection closed', [
                'class' => __CLASS__,
            ]);
            ClientManager::removeTunnelConnection($uri, $connection, $this->key);
        });

        // 单通道 接收所有权，处理后续数据请求
        if ($this->config['single_tunnel'] ?? false) {

            static::getLogger()->debug('current is single tunnel', []);

            $connection->removeAllListeners('data');
            $singleTunnel = new SingleTunnel();
            $singleTunnel->overConnection($connection);
            $singleTunnel->on('connection', function ($connection, $response) {
                $buffer = '';
                ClientManager::handleLocalConnection($connection, $this->config, $buffer, $response);
            });
        }

        PingPong::pingPong($connection, $connection->getRemoteAddress());
    }

    public function createDynamicTunnelConnections()
    {
        static::getLogger()->notice(__FUNCTION__, [
            'uuid' => $this->config['uuid'],
        ]);
        $tunneProtocol = $this->config['dynamic_tunnel_protocol'];

        (new Tunnel($this->config))->getTunnel($tunneProtocol)->then(function ($connection) {
            $connection->write(implode("\r\n", [
                'GET /client HTTP/1.1',
                'Host: ' . $this->config['tunnel_host'],
                'X-Is-Ptp: 1',
                'User-Agent: ReactPHP',
                'Secret-Key: ' . ($this->config['secret_key'] ?? ''),
                'Authorization: ' . ($this->config['token'] ?? ''),
                'Domain: ' . $this->config['domain'],
                'Uuid: ' . $this->config['uuid'],
                "\r\n"
            ]));
            $this->handleDynamicTunnelConnection($connection);
        });
    }


    public function handleDynamicTunnelConnection($connection)
    {
        static::getLogger()->notice(__FUNCTION__, [
            'uuid' => $this->config['uuid'],
        ]);
        $parseBuffer = new ParseBuffer();
        $parseBuffer->setConnection($connection);
        $parseBuffer->on('response', [$this, 'handleDynamicTunnelResponse']);
        $connection->on('data', [$parseBuffer, 'handleBuffer']);
        $connection->resume();
    }


    public function handleDynamicTunnelResponse($response, $parseBuffer)
    {
        $connection = $parseBuffer->getConnection();

        // 第一次创建代理成功
        if ($response->getStatusCode() === 200) {
            $this->addLocalDynamicConnection($connection, $response);
            // 第二次过来请求了
        } elseif ($response->getStatusCode() === 201) {
            $connection->removeAllListeners('data');
            $buffer = $parseBuffer->pullBuffer();
            ClientManager::handleLocalConnection($connection, $this->config, $buffer, $response);
        } else {
            static::getLogger()->error('error', [
                'status_code' => $response->getStatusCode(),
                'reason_phrase' => $response->getReasonPhrase(),
            ]);
            $connection->close();
        }
    }

    public function addLocalDynamicConnection($connection, $response)
    {
        $uri = $response->getHeaderLine('Uri');
        static::getLogger()->info('dynamic tunnel success ', [
            'class' => __CLASS__,
            'uri' => $uri,
            'response' => Helper::toString($response)
        ]);

       ClientManager::addDynamicTunnelConnection($uri, $connection);

        $connection->on('close', function () use ($uri, $connection) {
            static::getLogger()->info('local dynamic connection closed', [
                'class' => __CLASS__,
            ]);
            ClientManager::removeDynamicTunnelConnection($uri, $connection);
        });
    }

    public function close()
    {
        $this->close = true;
    }

}
