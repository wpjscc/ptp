<?php

namespace Wpjscc\Penetration\Log;

use Psr\Log\NullLogger;
use Psr\Log\LogLevel;

class LogManager implements LogManagerInterface
{
    use LogManagerTrait;

    static $logLevels = [];

    public static function getLogger()
    {
        if (empty(self::$logger)) {
            self::$logger = new NullLogger();
        }

        return self::$logger;
    }
}