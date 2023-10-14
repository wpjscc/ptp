<?php

namespace Wpjscc\Penetration\Tunnel\Server\Tunnel;

use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use RingCentral\Psr7;
use Wpjscc\Penetration\Helper;
use Wpjscc\Penetration\Utils\Ip;
use Wpjscc\Penetration\P2p\ConnectionManager;
use Wpjscc\Penetration\Utils\ParseBuffer;

class P2pTunnel extends EventEmitter implements ServerInterface, \Wpjscc\Penetration\Tunnel\SingleTunnelInterface,\Wpjscc\Penetration\Log\LogManagerInterface
{

    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    private $connection;
    private $protocol;
    private $remoteAddress;

    private $buffer = '';

    // code 410-420


    public function __construct()
    {
       

    }

    public function overConnection($connection)
    {
        $this->connection = $connection;
        $this->protocol = $connection->protocol;
        $this->remoteAddress = $connection->getRemoteAddress();
        ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['connection'] = $connection;
        $parseBuffer = new ParseBuffer;
        $parseBuffer->on('response', [$this, 'handleResponse']);
        $this->connection->on('data', [$parseBuffer, 'handleBuffer']);
        $this->connection->on('close', [$this, 'close']);
    }


    public function getAddress()
    {

    }

    public function pause()
    {

    }

    public function resume()
    {

    }

    public function close()
    {
        static::getLogger()->debug("P2pTunnel::".__FUNCTION__, [
            'class' => __CLASS__,
        ]);
        unset(ConnectionManager::$connections[$this->connection->protocol][$this->connection->getRemoteAddress()]);
    }

    protected function handleResponse($response)
    {
        if ($response->getStatusCode() === 410) {
            ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['local_address'] = $response->getHeaderLine('Local-Address');
            ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['ip_whitelist'] = $response->getHeaderLine('Ip-Whitelist');
            ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['ip_blacklist'] = $response->getHeaderLine('Ip-Blacklist');
            ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['is_need_local'] = $response->getHeaderLine('Is-Need-Local');
            ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['uuid'] = $response->getHeaderLine('Uuid');
            ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['try_tcp'] = $response->getHeaderLine('Try-Tcp');
            ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['token'] = $response->getHeaderLine('token');
            $this->connection->write("HTTP/1.1 411 OK\r\nAddress: {$this->remoteAddress}\r\n\r\n");
        }
        // 广播数据
        elseif ($response->getStatusCode() === 413) {
            if (!isset(ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['local_address'])) {
                static::getLogger()->error("p2p tunnel ignore broadcast", [
                    'class' => __CLASS__,
                    'response' => Helper::toString($response)
                ]);
                return;
            }
            ConnectionManager::broadcastAddress($this->protocol, $this->remoteAddress);
        }
        elseif ($response->getStatusCode() === 414) {
            if (!isset(ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['local_address'])) {
                static::getLogger()->error("p2p tunnel ignore broadcast", [
                    'class' => __CLASS__,
                    'response' => Helper::toString($response)
                ]);
                return;
            }
            if ($response->getHeaderLine('Address') != $this->remoteAddress) {
                static::getLogger()->error("p2p tunnel ignore broadcast no address", [
                    'class' => __CLASS__,
                    'response' => Helper::toString($response)
                ]);
                return;
            }

            $peer = $response->getHeaderLine('Peer');
            // 如果peer 不存在，忽视
            if (!isset(ConnectionManager::$connections[$this->protocol][$peer]['local_address'])) {
                static::getLogger()->error("p2p tunnel ignore broadcast no peer", [
                    'class' => __CLASS__,
                    'response' => Helper::toString($response)
                ]);
                return;
            }
            $realPeer = $response->getHeaderLine('Real-Peer');
            if (!$response->getHeaderLine('Real-Peer')) {
                static::getLogger()->error("p2p tunnel ignore broadcast no Real-Peer", [
                    'class' => __CLASS__,
                    'response' => Helper::toString($response)
                ]);
                return;
            }

            if (Ip::isPrivateUse($realPeer)) {
                ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['peers'][$peer]['local'][$realPeer] = $realPeer;
                // 双方都发过来确认消息，告诉双方可以tcp打孔了
                static::getLogger()->notice("p2p tunnel broadcast local", [
                    'class' => __CLASS__,
                    'response' => Helper::toString($response),
                    'peers' => [
                        'a' => ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['peers'] ?? [],
                        'b' => ConnectionManager::$connections[$this->protocol][$peer]['peers'][$this->remoteAddress] ?? []
                    ]
                ]);
                if (isset(ConnectionManager::$connections[$this->protocol][$peer]['peers'][$this->remoteAddress]['local'])) {
                    static::getLogger()->error("p2p tunnel broadcast local", [
                        'class' => __CLASS__,
                        'response' => Helper::toString($response)
                    ]);
                    ConnectionManager::broadcastAddressAndPeer($this->protocol, $this->remoteAddress, $peer);
                }
            } else {
                ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['peers'][$peer]['public'][$realPeer] = $realPeer;
                // 双方都发过来确认消息，告诉双方可以tcp打孔了
                static::getLogger()->notice("p2p tunnel broadcast public", [
                    'class' => __CLASS__,
                    'response' => Helper::toString($response)
                ]);
                if (isset(ConnectionManager::$connections[$this->protocol][$peer]['peers'][$this->remoteAddress]['public'])) {
                    static::getLogger()->error("p2p tunnel broadcast public", [
                        'class' => __CLASS__,
                        'response' => Helper::toString($response)
                    ]);
                    ConnectionManager::broadcastAddressAndPeer($this->protocol, $this->remoteAddress, $peer);
                }
            }

            
        }
        else {
            // ignore other response code
            static::getLogger()->warning("p2p tunnel ignore response", [
                'class' => __CLASS__,
                'response' => Helper::toString($response)
            ]);
        }
    }
}