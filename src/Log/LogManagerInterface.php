<?php

namespace Wpjscc\PTP\Log;

use Psr\Log\LoggerInterface;

interface LogManagerInterface
{
    public static function setLogger(LoggerInterface $logger);
    
}