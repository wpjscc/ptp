<?php

namespace Wpjscc\PTP\Dashboard;

use Wpjscc\PTP\Helper;
use Wpjscc\PTP\Config;

class DashboardManager
{
    use \Wpjscc\PTP\Traits\Singleton;
    use \Wpjscc\PTP\Traits\RunPort;

    protected function init()
    {
        if ($this->key == 'client') {
            $this->ports = array_filter([Config::instance($this->key)->getClientDashboardPort()]);
        } else {
            $this->ports = array_filter([Config::instance($this->key)->getServerDashboardPort()]);
        }
    }


    protected function runPort($port)
    {
        if ($this->key == 'client') {
            (new ClientDashboard(
                $port
            ))->run();
        } else {
            (new ServerDashboard(
                $port
            ))->run();
        }

    }

    public function check()
    {
        // $this->checkPorts(Config::instance($this->key)->getClientDashboardPort());
    }

   
}