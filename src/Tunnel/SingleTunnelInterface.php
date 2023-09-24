<?php 

namespace Wpjscc\Penetration\Tunnel;


interface SingleTunnelInterface
{
    public function overConnection(\React\Socket\ConnectionInterface $connection);
}