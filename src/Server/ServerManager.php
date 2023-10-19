<?php

namespace Wpjscc\PTP\Server;

use Wpjscc\PTP\Tunnel\Server\Tunnel;
use Wpjscc\PTP\Config;
use Wpjscc\PTP\Bandwidth\FileBandwidthManager;

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

        // 服务端带宽设置(默认1M, 最大5M)
        FileBandwidthManager::instance('dashboard')->setBandwidth(
            1024 * 1024 * 1024 * Config::instance('server')->getValue('dashboard.max_bandwidth', 5),
            1024 * 1024 * 1024 * Config::instance('server')->getValue('dashboard.bandwidth', 1),
            1000
        );
        
    }


    public function getTransformConfigs()
    {
        return $this->configs;
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
        $this->info['cert'] = $this->configs['cert'] ?? [];
    }

    public function check()
    {
        Config::instance('server')->refresh();

        HttpManager::instance('server')->check();
        TcpManager::instance('server')->check();
        UdpManager::instance('server')->check();
        FileBandwidthManager::instance('dashboard')->setBandwidth(
            1024 * 1024 * 1024 * Config::instance('server')->getValue('dashboard.max_bandwidth', 5),
            1024 * 1024 * 1024 * Config::instance('server')->getValue('dashboard.bandwidth', 1),
            1000
        );
    }
}