<?php

namespace Wpjscc\PTP\Server;

use Wpjscc\PTP\Tunnel\Server\Tunnel;
use Wpjscc\PTP\Config;

class ServerManager implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;
    use \Wpjscc\PTP\Traits\Singleton;

    protected $configs = [];

    protected $info = [
        'version' => '0.0.1',
        'tunnel_host' => '',
        'tunnel_80_port' => '',
        'tunnel_443_port' => '',
    ];

    public function getInfo()
    {
        return $this->info;
    }

    protected function init()
    {
        $this->configs = Config::instance('server')->getConfigs();
    }
    
    protected $running = false;


    public function run()
    {
        if ($this->running) {
            return;
        }
        $this->running = true;

        $this->runCommon();

        HttpManager::instance('server')->run();
        TcpManager::instance('server')->run();
        UdpManager::instance('server')->run();
        
    }

    protected function runCommon()
    {
        $tunnel = new Tunnel(
            $this->configs['common'],
            $this->configs['cert'] ?? []
        );
        $tunnel->run();
        $this->info['tunnel_host'] = $this->configs['common']['tunnel_host'] ?? '';
        $this->info['tunnel_80_port'] = $this->configs['common']['tunnel_80_port'] ?? '';
        $this->info['tunnel_443_port'] = $this->configs['common']['tunnel_443_port'] ?? '';
    }

    public function check()
    {
        HttpManager::instance('server')->check();
        TcpManager::instance('server')->check();
        UdpManager::instance('server')->check();
    }
}