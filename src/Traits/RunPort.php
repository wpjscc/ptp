<?php 

namespace Wpjscc\PTP\Traits;


trait RunPort
{
    protected $ip;
    protected $ports = [];

    protected $sockets = [];

    protected $running = false;

    public function run()
    {
        if ($this->running) {
            return;
        }
        
        foreach ($this->ports as $port) {
           $this->runPort($port);
        }

    }
    
    protected function addPort($port)
    {
        if (isset($this->sockets[$port]) || in_array($port, $this->ports)) {
            return;
        }

        $this->ports[] = $port;
        $this->runPort($port);
    }

    protected function removePort($port)
    {
        if (!isset($this->sockets[$port]) || !in_array($port, $this->ports)) {
            return;
        }
        $this->sockets[$port]->close();
        unset($this->sockets[$port]);
        $index = array_search($port, $this->ports);
        unset($this->ports[$index]);
    }

    public function checkPorts($ports)
    {
        $ports = array_unique($ports);
        $addPorts = array_diff($ports, $this->ports);
        $removePorts = array_diff($this->ports, $ports);

        foreach ($addPorts as $port) {
            $this->addPort($port);
        }

        foreach ($removePorts as $port) {
            $this->removePort($port);
        }

        return [$addPorts, $removePorts];
    }

    public function getIp()
    {
        return $this->ip;
    }

    public function getPorts()
    {
        return $this->ports;
    }


    abstract protected function runPort($port);

    abstract public function check();


}

