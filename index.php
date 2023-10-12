<?php

if (getParam('-c')) {
    require 'client.php';
} else {
    require 'server.php';
}

function getParam($key, $default = null){
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, $key) !==false){
            $keyValue = explode('=', $arg);
            if (count($keyValue) > 1) {
                return $keyValue[1];
            }
            if (count($keyValue) == 1) {
                return true;
            }
        }
    }
    return $default;
}