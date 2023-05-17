<?php

namespace App\Core;

use \App\Core\Response;

class Router 
{
    public function dispatch()
    {
        $parseUrl = parse_url($_SERVER['REQUEST_URI']);
        $urls = explode('/', trim($parseUrl['path'], '/'));
        
        $controller = ucfirst(strtolower(!empty($urls[0]) ? $urls[0] : 'default'));
        $action = strtolower($urls[1] ?? 'index');
        
        $controllerPath = '\App\Controller\\' . $controller . 'Controller';

        if (class_exists($controllerPath) && method_exists($controllerPath, $action)) {        
            (new $controllerPath())->$action();
        } else {
            $response = new Response();
            $response->setHeader('HTTP/1.1 404 Not Found');
            $response->setContent(['error' => 'Not Found']);
            $response->outputJson();
        }
    }
}


