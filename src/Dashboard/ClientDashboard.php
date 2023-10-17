<?php

namespace Wpjscc\PTP\Dashboard;

use FrameworkX\App;
use Wpjscc\PTP\Bandwidth\FileBandwidthManager;
use React\Stream\ThroughStream;

class ClientDashboard
{
    protected $port;
    protected $assetPath;

    public function __construct($port)
    {
        $this->assetPath = getcwd() . '/assets';
        $this->port = $port;
        putenv("X_LISTEN=0.0.0.0:$port");
    }

    public function run()
    {
        $app = new App();
        $app->get('/', function () {
            return new \React\Http\Message\Response(
                \React\Http\Message\Response::STATUS_OK,
                array(
                    'Content-Type' => 'text/html; charset=utf-8',
                ),
                $this->fileStream($this->assetPath . '/client.html')
            );
        });
        $app->get('/assets/build/{path}', function (\Psr\Http\Message\ServerRequestInterface $request) {
            $path = $request->getAttribute('path');
            if (!file_exists($this->assetPath . '/build/' . $path)) {
                return new \React\Http\Message\Response(
                    \React\Http\Message\Response::STATUS_OK,
                    array(
                        'Content-Type' => 'text/html; charset=utf-8',
                    ),
                    '<center><h1>404 Not Found</h1></center>'
                );
            }
            
            return new \React\Http\Message\Response(
                \React\Http\Message\Response::STATUS_OK,
                array(
                    'Content-Type' => pathinfo($path, PATHINFO_EXTENSION)  .'; charset=utf-8'
                ),
                $this->fileStream($this->assetPath . '/build/' . $path)
            );
        });
        
        $app->run();
    }


    protected function fileStream($filepath)
    {
        $stream = new ThroughStream;
        FileBandwidthManager::instance('client_dashboard')->addStream($stream, $filepath);
        return $stream;
    }
}