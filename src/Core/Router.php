<?php
declare(strict_types=1);

namespace App\Core;

class Router
{
    public function dispatch(): void
    {
        $parseUrl = parse_url($_SERVER['REQUEST_URI'] ?? '/');
        $urls = explode('/', trim(is_array($parseUrl) ? ($parseUrl['path'] ?? '') : '', '/'));

        $controller = ucfirst(strtolower(!empty($urls[0]) ? $urls[0] : 'default'));
        $action = strtolower($urls[1] ?? 'index');

        $controllerPath = '\\App\\Controller\\' . $controller . 'Controller';

        if (class_exists($controllerPath) && method_exists($controllerPath, $action)) {
            try {
                (new $controllerPath())->$action();
            } catch (\UnexpectedValueException $exception) {
                $response = new Response();
                $response->setHeader('HTTP/1.1 400 Bad Request');
                $response->setContent([
                    'error' => 'Bad Request',
                    'message' => $exception->getMessage(),
                ]);
                $response->outputJson();
            }
        } else {
            $response = new Response();
            $response->setHeader('HTTP/1.1 404 Not Found');
            $response->setContent(['error' => 'Not Found']);
            $response->outputJson();
        }
    }
}


