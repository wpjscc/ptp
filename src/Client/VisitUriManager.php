<?php 

namespace Wpjscc\PTP\Client;

class VisitUriManager
{
    protected static $visitUriToInfo = [];

    public static function addUriToken($uri, $token)
    {
        if ($uri && $token) {
            static::$visitUriToInfo[$uri]['tokens'][$token] = $token;
        }
    }

    public static function removeUriToken($uri, $token)
    {
        unset(static::$visitUriToInfo[$uri]['tokens'][$token]);
    }

    public static function getUriTokens($uri)
    {
        return static::$visitUriToInfo[$uri]['tokens'] ?? [];
    }

    public static function addUriRemoteProxy($uri, $remoteProxy)
    {
        if ($uri && $remoteProxy) {
            static::$visitUriToInfo[$uri]['remote_proxy'][$remoteProxy] = $remoteProxy;
        }
    }

    public static function removeUriRemoteProxy($uri, $remoteProxy)
    {
        unset(static::$visitUriToInfo[$uri]['remote_proxy'][$remoteProxy]);
    }

    public static function getUriRemoteProxy($uri)
    {

        $remoteProxys = static::$visitUriToInfo[$uri]['remote_proxy'] ?? [];
        if (empty($remoteProxys)) {
            return null;
        }
        // 随机一个
        return array_rand($remoteProxys);

    }

    public static function getUris()
    {
        return array_keys(static::$visitUriToInfo);
    }

}