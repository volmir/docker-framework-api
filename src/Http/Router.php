<?php
declare(strict_types=1);

namespace App\Http;

use PDO;
use RuntimeException;

final class Router
{
    /** @param array<string, string> $routes */
    public function __construct(private array $routes, private PDO $pdo)
    {
    }

    /**
     * @param Request $request Incoming request.
     * @return Response Response produced by the route handler.
     * @throws RuntimeException If a route target, controller, or method is invalid.
     */
    public function dispatch(Request $request): Response
    {
        $path = $request->path;
        if (!isset($this->routes[$path])) {
            return Response::json(['error' => 'Not Found'], 404);
        }

        $route = $this->routes[$path];
        $parts = explode('/', $route, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException("Invalid route target: {$route}");
        }

        $className = 'App\\Controller\\' . $parts[0];
        if (!class_exists($className)) {
            throw new RuntimeException("Controller not found: {$className}");
        }

        $method = $parts[1];
        if (!method_exists($className, $method)) {
            throw new RuntimeException("Method not found: {$className}::{$method}");
        }

        $controller = new $className($this->pdo);
        $result = $controller->{$method}($request);
        if ($result instanceof Response) {
            return $result;
        }

        return Response::json(['error' => 'Controller returned ' . get_debug_type($result)], 500);
    }
}
