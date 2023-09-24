<?php

namespace Wpjscc\Penetration\Log;

use Psr\Log\LoggerInterface;

interface LogManagerInterface
{
    public static function setLogger(LoggerInterface $logger);
    
}