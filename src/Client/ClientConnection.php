<?php

namespace Wpjscc\Penetration\Client;

class ClientConnection
{
    
    public $clientConnection;
    public $request;

    public function __construct($clientConnection, $request)
    {
        $this->clientConnection = $clientConnection;
        $this->request = $request;
    }
}