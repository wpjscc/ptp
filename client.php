<?php

require 'vendor/autoload.php';

use Wpjscc\Penetration\Client\ClientManager;
use Wpjscc\Penetration\Log\LogManager;
use Psr\Log\LogLevel;
use Wpjscc\Penetration\Config;
use Wpjscc\Penetration\Helper;
use RingCentral\Psr7\Response;
use RingCentral\Psr7;
use Clue\React\Zlib\Compressor;
use Clue\React\Zlib\Decompressor;
use React\Promise\Deferred;

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

// function decompressed($data) {
//     $decompressor = new Decompressor(ZLIB_ENCODING_GZIP);
//     $deferred = new Deferred();
//     $buffer = '';
//     $decompressor->on('data', function ($chunk) use (&$buffer) {
//         $buffer .= $chunk;
//     });
//     $decompressor->on('end', function () use (&$buffer, $deferred) {
//         $deferred->resolve($buffer);
//         $buffer = '';
//     });
//     $decompressor->end($data);
//     return $deferred->promise();
// }

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


// exit();

$config = Config::getConfig(getParam('--ini-path', './client.ini'));

LogManager::$logLevels = [
    // LogLevel::ALERT,
    // LogLevel::CRITICAL,
    // LogLevel::DEBUG,
    // LogLevel::EMERGENCY,
    LogLevel::ERROR,
    // LogLevel::INFO,
    // LogLevel::WARNING,
    LogLevel::NOTICE,

];
LogManager::setLogger(new \Wpjscc\Penetration\Log\EchoLog());
ClientManager::createLocalTunnelConnection($config);

function getParam($key, $default = null){
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, $key) !==false){
            return explode('=', $arg)[1];
        }
    }
    return $default;
}
