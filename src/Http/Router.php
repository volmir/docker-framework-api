<?php
declare(strict_types=1);

namespace App\Http;

use App\Database\Connection;

final class Router
{
    /** @param array<string, array{0: class-string, 1: string, 2?: list<class-string<Middleware>>}> $routes */
    public function __construct(private array $routes, private Connection $connection)
    {
    }

    public function handle(Request $request): Response
    {
        $key = $request->method . ' ' . $request->path;
        if (!isset($this->routes[$key])) {
            $allowed = [];
            foreach (array_keys($this->routes) as $route) {
                [$method, $path] = explode(' ', $route, 2);
                if ($path === $request->path) {
                    $allowed[] = $method;
                }
            }

            return $allowed === []
                ? Response::error(404, 'not_found', 'Not found')
                : Response::error(405, 'method_not_allowed', 'Method not allowed')
                    ->withHeader('Allow', implode(', ', array_unique($allowed)));
        }

        [$class, $method] = $this->routes[$key];
        $middleware = array_map(static fn(string $class): Middleware => new $class(), $this->routes[$key][2] ?? []);

        return Pipeline::run(
            $middleware,
            $request,
            fn(Request $request): Response => (new $class($this->connection))->{$method}($request),
        );
    }
}
