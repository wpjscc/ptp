<?php

namespace Wpjscc\PTP\Utils;

use Darsyn\IP\Version\IPv4;
use Darsyn\IP\Exception;

class Ip
{

    public static function getIpAndPort($address)
    {
        return strpos($address, '://') === false ? $address : explode('://', $address)[1];
    }
    public static function getIp($address)
    {
        return strpos($address, '://') === false ? explode(':', $address)[0] : explode(':', explode('://', $address)[1])[0] ;
    }

    public static function isPrivateUse($ip)
    {
        $ip = static::getIp($ip);
        $ip = IPv4::factory($ip);
        return $ip->isPrivateUse();
    }

    public static function addressInIpWhitelist($adress, $ipWhiitelist)
    {
        return static::addressInIplist($adress, $ipWhiitelist);
    }

    public static function addressInIpBlacklist($adress, $ipBlacklist)
    {
        return static::addressInIplist($adress, $ipBlacklist, 'blacklist');
    }

    public static function addressInIplist($address, $ipList, $type = 'whitelist')
    {
        if (empty($ipList)) {
            if ($type === 'whitelist') {
                return true;
            }
            return false;
        }
        $ip = strpos($address, '://') === false ? explode(':', $address)[0] : explode(':', explode('://', $address)[1])[0] ;

        $currentAddress = IPv4::factory($ip);
        $isInIpRange = false;
        $ipRange = explode(',', $ipList);
        
        try {
            foreach ($ipRange as $range) {
                $range = explode('/', $range);
                $rangeIp = IPv4::factory($range[0]);
                $rangeCidr = $range[1] ?? 32;
                if ($currentAddress->inRange($rangeIp, (int) $rangeCidr)) {
                    $isInIpRange = true;
                    break;
                }
            }
        } catch (Exception\InvalidIpAddressException $e) {
            echo 'The IP address supplied is invalid!';
            // $isInIpRange = false;
        }

        return $isInIpRange;
    }

    public static function getUri($host, $port, $protocol)
    {
        $uri = $host;

        try {
            
            IPv4::factory($host);
            if ($port) {
                $uri = $uri.':'.$port;
            }

            if ($protocol =='udp') {
                $uri = 'udp://'. $uri;
            }

        } catch (Exception\InvalidIpAddressException $e) {
            echo 'The IP address supplied is invalid!'."\n";
        }

        
        return $uri;
    }

    public static function isIp($host) 
    {
        try {
            IPv4::factory($host);
            return true;
        } catch (Exception\InvalidIpAddressException $e) {
            return false;
        }

    }

    
}