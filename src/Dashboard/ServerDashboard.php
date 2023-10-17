<?php

namespace Wpjscc\PTP\Dashboard;

use FrameworkX\App;
use Wpjscc\PTP\Bandwidth\FileBandwidthManager;
use React\Stream\ThroughStream;

class ServerDashboard
{
    protected $port;
    protected $assetPath;

    public function __construct($port)
    {
        $this->assetPath = getcwd() . '/assets';
        $this->port = $port;
        putenv("X_LISTEN=$port");
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
                $this->fileStream($this->assetPath . '/server.html')
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