<?php

namespace Wpjscc\PTP\Dashboard\Middlewares;

class BasicAuthMiddleware
{
    protected $key;
    public function __construct($key)
    {
        $this->key = $key;
    }
    public function __invoke(\Psr\Http\Message\ServerRequestInterface $request, callable $next)
    {
        $verify = \Wpjscc\PTP\Utils\BasicAuth::checkAuth(
            $request->getHeaderLine('Authorization'),
            \Wpjscc\PTP\Config::instance($this->key)->getValue('dashboard_'.$this->key.'.http_user,dashboard.http_user'),
            \Wpjscc\PTP\Config::instance($this->key)->getValue('dashboard_'.$this->key.'.http_pwd,dashboard.http_pwd')
        );

        if (!$verify) {
            return new \React\Http\Message\Response(
                \React\Http\Message\Response::STATUS_UNAUTHORIZED,
                array(
                    'Content-Type' => 'text/html; charset=utf-8',
                    'WWW-Authenticate' => 'Basic realm="PTP"',
                ),
                '<center><h1>401 Unauthorized</h1></center>'
            );
        }
        return $next($request);
    }
}