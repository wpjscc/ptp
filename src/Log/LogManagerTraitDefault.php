<?php

namespace Wpjscc\PTP\Log;

use Psr\Log\LoggerInterface;

trait LogManagerTraitDefault
{
    /**
     * The logger instance.
     *
     * @var LoggerInterface|null
     */
    protected static $logger;

    /**
     * Sets a logger.
     *
     * @param LoggerInterface $logger
     */
    public static function setLogger(LoggerInterface $logger)
    {
        self::$logger = $logger;
    }

    public static function getLogger()
    {
        if (empty(self::$logger)) {
            return LogManager::getLogger();
        }

        return self::$logger;
    }
}