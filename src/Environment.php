<?php

namespace Wpjscc\PTP;


use Wpjscc\PTP\Client\VisitUriMannager;
use Wpjscc\PTP\Server\TcpManager;
use Wpjscc\PTP\Server\UdpManager;
use Wpjscc\PTP\Server\Http;

class Environment
{

    // client or server
    public static $type = 'client';

    public static $manager = [];

    public static function addHttpServer(Http $httpServer)
    {
        self::$manager['httpServer'] = $httpServer;
    }

    public static function getHttpServer()
    {
        return self::$manager['httpServer'] ?? null;
    }

    // remove http 
    public static function removeHttpServer()
    {
        unset(self::$manager['httpServer']);
    }
    

    public static function addTcpManager(TcpManager $tcpManager)
    {
        self::$manager['tcpManager'] = $tcpManager;
    }

    public static function getTcpManager()
    {
        return self::$manager['tcpManager'] ?? null;
    }


    public static function addUdpManager(UdpManager $udpManager)
    {
        self::$manager['udpManager'] = $udpManager;
    }

    public static function getUdpManager()
    {
        return self::$manager['udpManager'] ?? null;
    }

}