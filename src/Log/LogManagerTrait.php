<?php

namespace Wpjscc\Penetration\Log;

use Psr\Log\LoggerInterface;

trait LogManagerTrait
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

    abstract static function getLogger();
}