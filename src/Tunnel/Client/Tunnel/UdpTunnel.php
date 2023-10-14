<?php

namespace Wpjscc\PTP\Tunnel\Client\Tunnel;

use React\Socket\ConnectorInterface;
use Wpjscc\PTP\CompositeConnectionStream;
use Wpjscc\PTP\Helper;
use React\Stream\ThroughStream;
use Wpjscc\Kcp\KCP;
use Wpjscc\Bytebuffer\Buffer;

// 不支持kcp
class UdpTunnel implements ConnectorInterface, \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    protected $isKcp;

    public function __construct($isKcp = false)
    {
        $this->isKcp = $isKcp;
    }

    public function connect($uri)
    {
        $protocol = 'udp';
        static::getLogger()->debug(__FUNCTION__, [
            'class' => __CLASS__,
            'uri' => $uri,
            'protocol' => $protocol,
        ]);
        return (new \React\Datagram\Factory())->createClient($uri)->then(function (\React\Datagram\Socket $client) use ($uri, $protocol) {

           return $this->createConnection($client, $uri);
        }, function ($e) use ($uri, $protocol) {
            static::getLogger()->error($e->getMessage(), [
                'uri' => $uri,
                'protocol' => $protocol,
                'current_file' => __FILE__,
                'current_line' => __LINE__,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $e;
        })->otherwise(function ($e) use ($uri, $protocol) {
            static::getLogger()->error($e->getMessage(), [
                'uri' => $uri,
                'protocol' => $protocol,
                'current_file' => __FILE__,
                'current_line' => __LINE__,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $e;
        });
    }


    public function createConnection($client, $uri)
    {

        $protocol = 'udp';
        $start = Helper::getMillisecond();

        static::getLogger()->info("Connected!", [
            'class' => __CLASS__,
            'uri' => $uri,
            'protocol' => $protocol,
        ]);
        $kcp = null;
        $receiveBuffer = null;

        if ($this->isKcp) {
            $kcp = new KCP(11, 22, function (Buffer $buffer) use ($client) {
                // static::getLogger()->debug('sendKcpDataToServer', [
                //     'class' => __CLASS__,
                //     'length' => strlen((string)$buffer),
                //     'bufffer' => (string) $buffer,
                //     'bufffer_hex' => bin2hex((string)$buffer),
                // ]);
                $client->send($buffer);
            });
            $receiveBuffer = Buffer::allocate(1024 * 1024 * 50);
        }

        $read = new ThroughStream;
        $write = new ThroughStream;

        $write->on('data', function ($data) use ($client, $uri, $protocol, $kcp, $start) {
            // static::getLogger()->debug('sendDataToServer', [
            //     'class' => __CLASS__,
            //     'uri' => $uri,
            //     'protocol' => $protocol,
            //     'length' => strlen($data),
            //     'data' => $data,
            // ]);
            if ($kcp) {
                $result = $kcp->send(Buffer::new($data));
                // static::getLogger()->debug('sendKcpDataToServer', [
                //     'class' => __CLASS__,
                //     'uri' => $uri,
                //     'protocol' => $protocol,
                //     'length' => strlen($data),
                //     'data' => $data,
                //     'result' => $result,
                // ]);
                if ($result < 0) {
                    static::getLogger()->error('kcpSendError', [
                        'class' => __CLASS__,
                        'uri' => $uri,
                        'protocol' => $protocol,
                        'length' => strlen($data),
                        'data' => $data,
                        'result' => $result,
                    ]);
                }
                $kcp->update(Helper::getMillisecond() - $start);
                // $kcp->flush();
            } else {
                $client->send($data);
            }
        });

        $contection = new CompositeConnectionStream($read, $write, $client, 'udp');
        $client->on('message', function ($msg) use ($read, $uri, $protocol, $kcp, $receiveBuffer, $start) {
            // static::getLogger()->debug('receiveDataFromServer', [
            //     'class' => __CLASS__,
            //     'uri' => $uri,
            //     'protocol' => $protocol,
            //     'length' => strlen($msg),
            //     'buffer_hex' => bin2hex($msg)
            // ]);
            if ($kcp) {
                $kcp->input(Buffer::new($msg));
                // $kcp->update(Helper::getMillisecond()-$start);
                $kcp->flush();
                $size = 0;
                do {
                    $size = $kcp->recv($receiveBuffer);
                    // static::getLogger()->debug('receiveKcpDataFromServer', [
                    //     'class' => __CLASS__,
                    //     'uri' => $uri,
                    //     'protocol' => $protocol,
                    //     'size' => $size,
                    //     // 'receiveBuffer' => (string) $receiveBuffer,
                    //     // 'receiveBuffer_hex' => bin2hex($receiveBuffer),
                    // ]);
                    if ($size > 0) {
                        static::getLogger()->debug('解码成功', [
                            'class' => __CLASS__,
                            'uri' => $uri,
                            'protocol' => $protocol,
                            'size' => $size,
                            'data' => (string) $receiveBuffer->slice(0, $size),
                        ]);
                        $read->write($receiveBuffer->slice(0, $size));
                    }
                } while ($size >= 0);
            } else {
                $read->write($msg);
            }
        });

        $client->on('error', function ($error, $client) use ($contection, $uri, $protocol) {
            static::getLogger()->error($error->getMessage(), [
                'class' => __CLASS__,
                'uri' => $uri,
                'protocol' => $protocol,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ]);
            $client->send("POST /close HTTP/1.1\r\n\r\n");
            $client->close();
            $contection->close();
        });

        $timer = null;
        if ($kcp) {
            $timer = \React\EventLoop\Loop::addPeriodicTimer(0.01, function () use ($kcp, $start) {
                if($kcp->getWaitSnd() > 0) {
                    static::getLogger()->debug('kcpUpdate', [
                        'class' => __CLASS__,
                        'start' => $start,
                        'millisecond' => Helper::getMillisecond(),
                        'diff' => Helper::getMillisecond() - $start,
                        'wait_snd' => $kcp->getWaitSnd(),
                    ]);
                    $kcp->update(Helper::getMillisecond() - $start);
                }
            });
            $kcp->setNodelay(true, 2, true);
            $kcp->setInterval(10);
        }
        $contection->on('close', function () use ($client, $uri, $protocol, $timer) {
            if ($timer) {
                \React\EventLoop\Loop::cancelTimer($timer);
            }
            static::getLogger()->info('connectionClosed-2', [
                'uri' => $uri,
                'protocol' => $protocol,
            ]);
            \React\EventLoop\Loop::addTimer(0.001, function () use ($client) {
                $client->close();
            });
        });

       

        return  $contection;
    }
}
