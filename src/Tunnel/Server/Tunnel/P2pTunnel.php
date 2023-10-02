<?php

namespace Wpjscc\Penetration\Tunnel\Server\Tunnel;

use Evenement\EventEmitter;
use React\Socket\ServerInterface;
use RingCentral\Psr7;
use Wpjscc\Penetration\Helper;
use Wpjscc\Penetration\P2p\ConnectionManager;

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
        var_dump('overp2pConnection', $this->protocol, $this->remoteAddress);
       
        $this->connection->on('data', function($buffer){
            var_dump('p2pTunnelData', $buffer);
            $this->buffer .= $buffer;
            $this->parseBuffer();
        });
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
        static::getLogger()->error("P2pTunnel::".__FUNCTION__, [
            'class' => __CLASS__,
        ]);
        unset(ConnectionManager::$connections[$this->connection->protocol][$this->connection->getRemoteAddress()]);
    }

    protected function parseBuffer()
    {

        $pos = strpos($this->buffer, "\r\n\r\n");
        if ($pos !== false) {
            $httpPos = strpos($this->buffer, "HTTP/1.1");
            if ($httpPos === false) {
                $httpPos = 0;
            }
            try {
                $response = Psr7\parse_response(substr($this->buffer, $httpPos, $pos-$httpPos));
            } catch (\Exception $e) {
                // invalid response message, close connection
                static::getLogger()->error($e->getMessage(), [
                    'class' => __CLASS__,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'buffer' => substr($this->buffer, $httpPos, $pos-$httpPos)
                ]);

                $this->buffer = substr($this->buffer, $pos + 4);
                
                return;
            }

            $this->buffer = substr($this->buffer, $pos + 4);

            // 收到local_address
            if ($response->getStatusCode() === 410) {
                var_dump('p2pTunnel410', $response->getHeaderLine('Local-Address'));
                ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['local_address'] = $response->getHeaderLine('Local-Address');
                // var_dump('p2pTunnel410', ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['local_address']);
                ConnectionManager::$connections[$this->protocol][$this->remoteAddress]['ip_range'] = $response->getHeader('Ip-Range');
                $this->connection->write("HTTP/1.1 411 OK\r\nAddress: {$this->remoteAddress}\r\n\r\n");
            }
            // 广播数据
            elseif ($response->getStatusCode() === 413) {
                // var_dump('p2pTunnel413', $response->getHeaderLine('Local-Address'));
                // var_dump(ConnectionManager::$connections[$this->protocol][$this->remoteAddress]);
                // exit();
                ConnectionManager::broadcastAddress($this->protocol, $this->remoteAddress);
            }
            else {
                // ignore other response code
                static::getLogger()->warning("p2p tunnel ignore response", [
                    'class' => __CLASS__,
                    'response' => Helper::toString($response)
                ]);

            }

            // 继续解析
            $this->parseBuffer();
        }
    }
}