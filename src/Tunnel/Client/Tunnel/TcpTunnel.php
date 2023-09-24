<?php

namespace Wpjscc\Penetration\Tunnel\Client\Tunnel;

use React\Socket\ConnectorInterface;

class TcpTunnel implements ConnectorInterface,\Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

    protected $connector;

    public function __construct($context = array(), $loop = null)
    {
       $this->connector = new \React\Socket\Connector($context, $loop);
    }

    public function connect($uri)
    {
        return $this->connector->connect($uri);
    }
    
   
}