<?php

namespace Wpjscc\PTP\Utils;

class BasicAuth
{
    public static function checkAuth($authorization,$username, $password)
    {
        if (!$authorization) {
            return false;
        }

        $auth = explode(' ', $authorization);
        if (count($auth) != 2 || $auth[0] !== 'Basic') {
            
            return;
        }

        $auth = base64_decode($auth[1]);
        $auth = explode(':', $auth);
        if (count($auth) != 2 || $auth[0] !== $username || $auth[1] !== $password) {
            return false;
        }
        return true;
    }
}