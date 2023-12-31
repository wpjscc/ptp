<?php

namespace Wpjscc\PTP;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final Class Helper 
{
    /**
     * Returns the string representation of an HTTP message.
     *
     * @param MessageInterface $message Message to convert to a string.
     */
    public static function toString(MessageInterface $message): string
    {
        if ($message instanceof RequestInterface) {
            $msg = trim($message->getMethod() . ' '
                    . $message->getRequestTarget())
                . ' HTTP/' . $message->getProtocolVersion();
            if (!$message->hasHeader('host')) {
                $msg .= "\r\nHost: " . $message->getUri()->getHost();
            }
        } elseif ($message instanceof ResponseInterface) {
            $msg = 'HTTP/' . $message->getProtocolVersion() . ' '
                . $message->getStatusCode() . ' '
                . $message->getReasonPhrase();
        } else {
            throw new \InvalidArgumentException('Unknown message type');
        }

        foreach ($message->getHeaders() as $name => $values) {
            if (strtolower($name) === 'set-cookie') {
                foreach ($values as $value) {
                    $msg .= "\r\n{$name}: " . $value;
                }
            } else {
                $msg .= "\r\n{$name}: " . implode(', ', $values);
            }
        }

        return "{$msg}\r\n\r\n";
    }

    public static function formatTime($secs)
    {
        static $timeFormats = [
            [0, '< 1 sec'],
            [1, '1 sec'],
            [2, 'secs', 1],
            [60, '1 min'],
            [120, 'mins', 60],
            [3600, '1 hr'],
            [7200, 'hrs', 3600],
            [86400, '1 day'],
            [172800, 'days', 86400],
        ];

        foreach ($timeFormats as $index => $format) {
            if ($secs >= $format[0]) {
                if ((isset($timeFormats[$index + 1]) && $secs < $timeFormats[$index + 1][0])
                    || $index == \count($timeFormats) - 1
                ) {
                    if (2 == \count($format)) {
                        return $format[1];
                    }

                    return floor($secs / $format[2]).' '.$format[1];
                }
            }
        }
    }

    public static function formatMemory(int $memory)
    {
        if ($memory >= 1024 * 1024 * 1024) {
            return sprintf('%.1f GiB', $memory / 1024 / 1024 / 1024);
        }

        if ($memory >= 1024 * 1024) {
            return sprintf('%.1f MiB', $memory / 1024 / 1024);
        }

        if ($memory >= 1024) {
            return sprintf('%d KiB', $memory / 1024);
        }

        return sprintf('%d B', $memory);
    }

    public static function encode($data)
    {
        return base64_encode($data);
    }
    
    public static function decode($data)
    {
        return base64_decode($data);
    }

    public static function valMaxHeaderSize($buffer)
    {
        $maxSize =  Config::instance('server')->getValue('common.max_header_size') ?: 1024 * 8;

        if (isset($buffer[$maxSize])) {
            return false;
        }
        return true;
    }

    public static function encrypt($data, $key, $iv) 
    {
        $cipher = "AES-256-CBC";
        $options = OPENSSL_RAW_DATA;
        $encryptedData = openssl_encrypt($data, $cipher, $key, $options, substr($iv, 0, 16));
        $encryptedData = base64_encode($encryptedData);
        return $encryptedData;
    }

    public static function decrypt($encryptedData, $key, $iv) 
    {
        $cipher = "AES-256-CBC";
        $options = OPENSSL_RAW_DATA;
        $encryptedData = base64_decode($encryptedData);
        $decryptedData = openssl_decrypt($encryptedData, $cipher, $key, $options, substr($iv, 0, 16));
        return $decryptedData;
    }

    public static function info()
    {

    }

    public static function getLocalHostAndPort($config)
    {
        $host = $config['local_host'] ?? '';
        $port = $config['local_port'] ?? '';
        if ($port) {
            $host .= ':' . $port;
        }
        return $host;
    }

    public static function getMillisecond() {
        // var_dump(time(), microtime(true));
        // exit();
        // return time();
        list($microSec, $sec) = explode(' ', microtime());
        $t =  (float) sprintf('%.0f', (floatval($microSec) + floatval($sec)) * 1000);
        return $t;
    }

    public static function validateSecretKey($secretKey)
    {
        $secretKeys = explode(',', Config::instance('server')->getValue('common.secret_key') ?: '');

        if (empty($secretKeys)) {
            return true;
        }

        if (in_array($secretKey, $secretKeys)) {
            return true;
        }

        return false;
    }
}