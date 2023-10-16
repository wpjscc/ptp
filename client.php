<?php

require 'vendor/autoload.php';

use Wpjscc\PTP\Client\ClientManager;
use Wpjscc\PTP\Log\LogManager;
use Psr\Log\LogLevel;
use Wpjscc\PTP\Config;
use Wpjscc\PTP\Helper;
use RingCentral\Psr7\Response;
use RingCentral\Psr7;
use Clue\React\Zlib\Compressor;
use Clue\React\Zlib\Decompressor;
use React\Promise\Deferred;
use Wpjscc\PTP\Client\VisitUriManager;
use Wpjscc\PTP\Server\Http;
use Wpjscc\PTP\P2p\Client\PeerManager;
use Wpjscc\PTP\P2p\ConnectionManager;
use Wpjscc\PTP\Server\TcpManager;
use Wpjscc\PTP\Server\UdpManager;
use Wpjscc\PTP\Proxy\ProxyManager;

// function compressor($data) {
//     $compressor = new Compressor(ZLIB_ENCODING_GZIP);
//     $deferred = new Deferred();
//     $buffer = '';
//     $compressor->on('data', function ($chunk) use (&$buffer) {
//         $buffer .= $chunk;
//     });
//     $compressor->on('end', function () use (&$buffer, $deferred) {
//         $deferred->resolve($buffer);
//         $buffer = '';
//     });
//     $compressor->end($data);
//     return $deferred->promise();
// }

function decompressed($data)
{
    $decompressor = new Decompressor(ZLIB_ENCODING_GZIP);
    $deferred = new Deferred();
    $buffer = '';
    $decompressor->on('data', function ($chunk) use (&$buffer) {
        $buffer .= $chunk;
    });
    $decompressor->on('end', function () use (&$buffer, $deferred) {
        $deferred->resolve($buffer);
        $buffer = '';
    });
    $decompressor->on('error', function ($e) use ($deferred) {
        $deferred->reject($e);
    });
    $decompressor->end($data);
    return $deferred->promise();
}

// $response = new Response(
//     200,
//     [
//         'Content-Type' => 'text/html; charset=UTF-8',
//         'Content-Length' => 0,
//         'Connection' => 'keep-alive',
//         'Keep-Alive' => 'timeout=5',
//         'X-Powered-By' => 'PHP/7.2.10',
//         'Set-Cookie' => 'PHPSESSID=5b5b0b2b0b2b0b2b0b2b0b2b0b2b0b2b; path=/',
//         'Expires' => 'Thu, 19 Nov 1981 08:52:00 GMT',
//         'Cache-Control' => 'no-store, no-cache, must-revalidate',
//         'Pragma' => 'no-cache',
//         'Date' => 'Thu, 25 Oct 2018 08:52:00 GMT',
//         'Server' => 'nginx/1.14.0',
//     ],
//     ''
// );

// $str = "中文            

// adasd
//                      sdadadd                   hello
// dasdasdas
// dadsad
// zzz
// world \r\n1";
// $length = strlen($str);
// $response = $response->withHeader('Content-Length', $length);
// $response = $response->withHeader('Content-Base64-Length', strlen(base64_encode($str)));

// compressor($str)->then(function ($data) use ($response) {

//     $response = $response->withHeader('Body', base64_encode($data));
//     $response = $response->withHeader('Content-Compress-Length', strlen($data));
//     $response = $response->withHeader('Content-Compress-Base64-Length', strlen(base64_encode($data)));

//     echo $string = Helper::toString($response);

//     $response = Psr7\parse_response($string);

//     $body = $response->getHeaderLine('Body');

//     return decompressed(base64_decode($body));
// }, function($e) {
//     echo $e->getMessage();
// })->then(function ($data) {
//     echo $data;
// });


// $response = $response->withHeader('Body', 
// $binary = pack('A*', $str)
// );

// var_dump($binary);
// var_dump(bin2hex($str));
// var_dump(hex2bin(bin2hex($binary)));

// echo bin2hex($binary);

// $response = $response->withHeader('Aa', 
// 'he'
// );


// echo $string = Helper::toString($response);

// $response = Psr7\parse_response($string);

// var_dump($response->getHeaderLine('Content-Length'));

// var_dump(unpack("A*body", $response->getHeaderLine('Body')));
// $data = file_get_contents('./test.txt');
// // $data = 'hello world';

// echo $length = (strlen($data))."\n";
// echo dechex($length)."\n";
// echo hexdec('1d80')."\n";
// echo hexdec('1ad2')."\n";
// decompressed($data)->then(function ($data) {
//     echo $data;
// }, function ($e) {
//     echo $e->getMessage();
// });
// exit();


\Wpjscc\PTP\Environment::$type = 'client';


if (getParam('-vvv')) {
    LogManager::$logLevels = [
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::DEBUG,
        LogLevel::EMERGENCY,
        LogLevel::ERROR,
        LogLevel::INFO,
        LogLevel::WARNING,
        LogLevel::NOTICE,

    ];
}

LogManager::setLogger(new \Wpjscc\PTP\Log\EchoLog());

ClientManager::instance('client')->run();


\React\EventLoop\Loop::get()->addPeriodicTimer(5, function () {
    ClientManager::instance('client')->check();
});




\React\EventLoop\Loop::addPeriodicTimer(2, function () {

    $info = ClientManager::instance('client')->getInfo();

    $httpManager = TcpManager::instance('client');
    $tcpManager = TcpManager::instance('client');
    $udpManager = UdpManager::instance('client');
    // server port
    echo "======> PTP Version -> " . $info['version'] . PHP_EOL;
    echo "======> tunnel server host -> " . $info['tunnel_host'] . PHP_EOL;
    echo "======> tunnel server [80] port listen at -> " . $info['tunnel_80_port'] . PHP_EOL;
    echo "======> tunnel server [443] port listen at -> " . $info['tunnel_443_port'] . PHP_EOL;
    // http ports
    echo "======> http ports listen at -> {$httpManager->getIp()}:" . implode(', ', $httpManager->getPorts()) . PHP_EOL;
    // tcp ports
    echo "======> tcp ports listen at -> {$tcpManager->getIp()}:" . implode(', ', $tcpManager->getPorts()) . PHP_EOL;
    // udp ports
    echo "======> udp ports listen at -> {$udpManager->getIp()}:" . implode(', ', $udpManager->getPorts()) . PHP_EOL;


    $uris = ClientManager::getTunnelUris();

    foreach ($uris as $uri) {
        echo "======> tunnel $uri count: " . ClientManager::getTunnelConnectionCount($uri) . PHP_EOL;
    }

    $uris = ClientManager::getDynamicTunnelUris();
    foreach ($uris as $uri) {
        echo "======> dynamic tunnel $uri count: " . ClientManager::getDynamicTunnelConnectionCount($uri) . PHP_EOL;
    }

    PeerManager::print();


    echo "======> p2p uris: " . implode(', ', array_map(function ($uri) use ($httpManager) {
        if (strpos($uri, ':') !== false) {
            return $uri;
        }
        return $uri . ':' . ($httpManager->getPorts()[0] ?? '');
    }, array_keys(ProxyManager::$remoteTunnelConnections))) . PHP_EOL;

    echo "======> visit uris: " . implode(', ', VisitUriManager::getUris()) . PHP_EOL . PHP_EOL;
});
