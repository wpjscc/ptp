<?php

namespace Wpjscc\PTP\Server;

use GuzzleHttp\Psr7\Header;
use Wpjscc\PTP\Proxy\ProxyManager;
use RingCentral\Psr7;
use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Tunnel\Server\Tunnel\TcpTunnel;

class Http implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    public $port = 8080;

    public function __construct($port = null)
    {
        if ($port) {
            $this->port = $port;
        }


    }

    public function run()
    {
     
        $tunnel = new TcpTunnel('0.0.0.0:'.$this->port);

        $tunnel->on('connection', function ($userConnection) {
            echo 'http user: '.$userConnection->getLocalAddress().' is connected'."\n";

            $first = true;
            $buffer = '';
            $userConnection->on('data', $fn = function ($chunk) use ($userConnection, &$buffer,  &$fn, &$first) {
                $buffer .= $chunk;
                $pos = strpos($buffer, "\r\n\r\n");

                // CONNECT
                // Support Server And Client
                // 部署在服务端 和 Wpjscc\PTP\Tunnel\Server\Tunnel 功能一样 |----------------------------------------------------------<-----------------------|
                // HTTP Proxy Request                                           |    |-----------------|                                                           |
                //                           Http Server <----------------------|    |  tunnel pool    |                                                           |
                //                             |------by domain find service-->----> |                 |                                                           |
                //                             |  |----------<----<------------------|---------|-------|                                                           |
                //               http/s Proxy  |  |                                            |                                                                   |
                //         +------>-->---------+--|-----------------+                          |                                                                   |
                //         |                      |                 |                          |                                                                   |
                //         |----------<----<------|                 |                          |                                                                   |
                //         |                                        |                          |                                                                   |
                //      Client A                                    Client B                   |                                                                   |
                //                                                                             |                                                                   |
                //                                                                             |                                                                   |
                //                                                                             |                                                                   |
                //                              Server                             |-----------|------|             |------------------|                           |
                //                                |-------------->------->---------|                  |             |                  |                           |
                //                                |                                |  tunnel pool     |------------>| local.test       |                           |
                //                                |                                |------------------|             |                  |                           |
                //                                |                                                                 | 192.168.1.1:3000 |--<---|                    |
                //                                |                                                                 | www.domain.com   |      |                    |
                //                                |                                                                 |------------------|      |                    |
                //         +----------register----+-----register---------+-----register------------+                                          |                    |
                //         |                                             |                         |                                          |                    |
                //         |                                             |                         |                                          |                    |
                //         |                                             |                         |                                          |                    |
                //      Client A                                      Client B local.test          Client C 192.168.1.1:3000,www.domain.com   |                    |
                //            |                                                                                                               |                    |
                //            |                                                                                                               |                    |          
                //            |                                          |-------|                                                            |                    |  
                //            |                                          |       |                                                            |                    |      
                //            |---by proxy can visit---------------------|Server |------------------------------------------------------------|                    |
                //                                                       |-------|                                                                                 |
                //                                                                                                                                                 |
                //                                                                                                                                                 |
                //                                                                                                                                                 |
                // 部署在客户端                                                                                                                                      |
                //                              Local Http Server                                                                                                  |
                //                                |                                   |------------------------|                                                   |
                //                                |                                   |  local p2p tunnel pool |                                                   |
                //                                |--------------->-------------------|                        |------not exits------------->-----------------------|
                //                                |                                   |------------------------|
                //                                |                                   
                //                                +-----------<---------+
                //                                                      |
                //                                                      |
                //                                                      |
                //                                                      |
                //                                             Local User Visit
                if ($first && ($pos !== false) && (strpos($buffer, "CONNECT") === 0)) {
                    $userConnection->removeListener('data', $fn);
                    $fn = null;
                    try {
                        $token = '';
                        $pattern = '/Proxy-Authorization: ([^\r\n]+)/i';
                        if (preg_match($pattern, $buffer, $matches1)) {
                            $proxyAuthorizationValue = $matches1[1];
                            $token = $proxyAuthorizationValue;
                        }
                        $pattern = "/CONNECT ([^\s]+) HTTP\/(\d+\.\d+)/";
                        if (preg_match($pattern, $buffer, $matches)) {
                            $host = $matches[1];
                            $version = $matches[2];
                            $userConnection->write("HTTP/{$version} 200 Connection Established\r\n\r\n");
                            $request = Psr7\parse_request("GET /connect HTTP/1.1\r\nHost: $host\r\nProxy-Authorization: {$token}\r\n\r\n");
                            ProxyManager::pipe($userConnection, $request);
                            $buffer = '';
                        } else {
                            $buffer = '';
                            $userConnection->write('Invalid request');
                            $userConnection->end();
                        }
                    } catch (\Exception $e) {
                        static::getLogger()->error($e->getMessage(), [
                            'class' => __CLASS__,
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ]);
                        $buffer = '';
                        $userConnection->write($e->getMessage());
                        $userConnection->end();
                    }
                    return;
                }
                if ($pos !== false) {
                    $first = false;

                    $userConnection->removeListener('data', $fn);
                    $fn = null;
                    // try to parse headers as request message
                    try {
                        $request = Psr7\parse_request(substr($buffer, 0, $pos));
                    } catch (\Exception $e) {
                        // invalid request message, close connection
                        $buffer = '';
                        $userConnection->write($e->getMessage());
                        $userConnection->close();
                        return;
                    }

                    ProxyManager::pipe($userConnection, $request, $buffer);

                }
                

            });
            

        });

        echo "Http and Proxy Server is running at {$this->port}...\n";
    }
}