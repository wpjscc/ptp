<?php

namespace Wpjscc\Penetration\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Wpjscc\Penetration\Utils\CliColor;

class EchoLog extends AbstractLogger
{
    public function log($level, $message, array $context = array())
    {

        if (!in_array($level, LogManager::$logLevels)) {
            return;
        }

        $msg = $message;

        // 时间带着毫秒
        $date = '['.date('Y-m-d H:i:s') . '.' . sprintf('%06d', floor(microtime(true) * 1000000) % 1000000) . ']';

        $msg = $date.' '. strtoupper($level). ': '. $msg;

        if ($level == LogLevel::ERROR) {
            echo CliColor::red(). $msg . PHP_EOL;
        } elseif ($level == LogLevel::WARNING) {
            echo CliColor::yellow(). $msg . PHP_EOL;
        } elseif ($level == LogLevel::NOTICE) {
            echo CliColor::green().$msg . PHP_EOL;
        } elseif ($level == LogLevel::INFO) {
            echo CliColor::blue(). $msg . PHP_EOL;
        } elseif ($level == LogLevel::DEBUG) {
            echo CliColor::cyan().'Debug: ' . $msg . PHP_EOL;
        }

        if (!empty($context)) {
            echo json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }

        CliColor::reset();


    }

}