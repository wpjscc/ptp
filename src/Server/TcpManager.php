<?php

namespace Wpjscc\PTP\Server;


class TcpManager
{
    protected $ip;
    protected $ports = [];

    protected $sockets = [];

    public static function create($ip, $ports = [])
    {
        return new static($ip, $ports);
    }

    public function __construct($ip, $ports = [])
    {
        $this->ip = $ip;
        $this->ports = $ports;

    }

    public function run()
    {
        foreach ($this->ports as $port) {
           $this->runPort($port);
        }

    }

    protected function runPort($port)
    {
        $tcpServer = new Tcp(
            $this->ip,
            $port
        );
        $this->sockets[$port] = $tcpServer->run();
    }

    protected function addPort($port)
    {
        if (isset($this->sockets[$port]) || in_array($port, $this->ports)) {
            return;
        }

        echo "add tcp port: {$port}\n";

        $this->ports[] = $port;
        $this->runPort($port);
    }

    protected function removePort($port)
    {
        if (!isset($this->sockets[$port]) || !in_array($port, $this->ports)) {
            return;
        }
        echo "remove tcp port: {$port}\n";
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

}