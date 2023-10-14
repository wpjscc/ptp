<?php 

namespace Wpjscc\PTP\Tunnel;


interface SingleTunnelInterface
{
    public function overConnection(\React\Socket\ConnectionInterface $connection);
}