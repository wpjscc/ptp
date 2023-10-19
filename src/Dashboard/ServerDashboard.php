<?php

namespace Wpjscc\PTP\Dashboard;

use FrameworkX\App;
use Wpjscc\PTP\Bandwidth\FileBandwidthManager;
use React\Stream\ThroughStream;
use Wpjscc\PTP\Action\ActionManager;
use React\Http\Message\Response;

class ServerDashboard
{
    protected $key = 'server';

    protected $port;
    protected $assetPath;

    public function __construct($port)
    {
        $this->assetPath = getcwd() . '/assets';
        $this->port = $port;
        putenv("X_LISTEN=0.0.0.0:$port");
    }


    public function run()
    {
        $app = new App();

        $app->addGroup('', [
            new Middlewares\BasicAuthMiddleware('server')
        ], function ($app) {
            $app->get('/', function () {
                return new Response(
                    Response::STATUS_OK,
                    array(
                        'Content-Type' => 'text/html; charset=utf-8',
                    ),
                    $this->fileStream($this->assetPath . '/src/server.html')
                );
            });
            $app->get('/assets/dist/{path}', function (\Psr\Http\Message\ServerRequestInterface $request) {
                $path = $this->assetPath . '/dist/' . $request->getAttribute('path');
                if (!file_exists($path)) {
                    return new Response(
                        Response::STATUS_OK,
                        array(
                            'Content-Type' => 'text/html; charset=utf-8',
                        ),
                        '<center><h1>404 Not Found</h1></center>'
                    );
                }


                return new Response(
                    Response::STATUS_OK,
                    array(
                        'Content-Type' => [
                            'js' => 'application/javascript',
                            'css' => 'text/css',
                        ][pathinfo($path, PATHINFO_EXTENSION)] ?? 'octet-stream'
                    ),
                    $this->fileStream($path)
                );
            });


            $app->addGroup('/api', [], function ($app) {
                $app->map([
                    "GET",
                    "POST"
                ], '/events/{event}', function ($request) {
                    $params = $request->getQueryParams();
                    $event = $request->getAttribute('event');
                    $events = explode(',', $event);
                    $methodToParams = [];
                    $extra = [];
                    foreach ($events as $method) {
                        if (method_exists(ActionManager::instance($this->key), $method)) {
                            $className = get_class(ActionManager::instance($this->key));
                            $rp = new \ReflectionClass($className);
                            $methodParameters = [];
                            $rpParameters = $rp->getMethod($method)->getParameters();
                            foreach ($rpParameters as $rpParameter) {
                                $name = $rpParameter->getName();
                                $position = $rpParameter->getPosition();
                                if (isset($params[$method][$name])) {
                                    $methodParameters[$position] = $params[$method][$name];
                                } else {
                                    if ($rpParameter->isOptional()) {
                                        $methodParameters[$position] = $rpParameter->getDefaultValue();
                                    } else {
                                        return  Response::json([
                                            'code' => 1,
                                            'msg' => "方法 $method 缺少 $name 参数",
                                            'data' => []
                                        ]);
                                    }
                                }
                            }
                            $methodToParams[$method] = $methodParameters;
                        } else {
                            $extra[] = "方法 $method 不存在 或 缺少参数";
                        }
                    }
        
        
                    if (empty($methodToParams)) {
                        return Response::json([
                            'code' => 1,
                            'msg' => implode(',', $extra),
                            'data' => []
                        ]);
                    }
                    
                    $data = [];
                    foreach ($methodToParams as $m => $p) {
                        try {
                            $data[$m] = ActionManager::instance($this->key)->{$m}(...$p);
                        } catch (\Exception $e) {
                           $extra[] = $e->getMessage();
                        }
                    }
                    return Response::json([
                        'code' => 0,
                        'msg' => 'ok',
                        'extra' => $extra,
                        'data' => $data
                    ]);

                });
            });
        });
        $app->run();
    }



    protected function fileStream($filepath)
    {
        $stream = new ThroughStream;
        FileBandwidthManager::instance('dashboard')->addStream($stream, $filepath);
        return $stream;
    }
}