<?php

namespace Wpjscc\PTP;

final class Connection
{

    protected $localAddress;
    protected $remoteAddress;

    public function __construct($localAddress, $remoteAddress)
    {
        $this->localAddress = $localAddress;
        $this->remoteAddress = $remoteAddress;
    }

    public function getLocalAddress()
    {
        return $this->localAddress;
    }

    public function getRemoteAddress()
    {
        return $this->remoteAddress;
    }
}