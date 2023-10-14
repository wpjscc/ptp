<?php

namespace Wpjscc\PTP;

use React\Socket\ServerInterface;
use Evenement\EventEmitter;

final class DecorateSocket extends EventEmitter implements ServerInterface
{
    private $server;

    public function __construct(ServerInterface $server)
    {
        $this->server = $server;
    }
    
    public function getAddress()
    {
        return $this->server->getAddress();
    }

    public function pause()
    {
        $this->server->pause();
    }

    public function resume()
    {
        $this->server->resume();
    }

    public function close()
    {
        $this->server->close();
    }
}