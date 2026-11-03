<?php

declare(strict_types=1);

namespace App\Http;

final class Pipeline
{
    /** @param list<Middleware> $middleware
     * @param callable(Request):Response $core */
    public static function run(array $middleware, Request $request, callable $core): Response
    {
        foreach (array_reverse($middleware) as $layer) {
            $next = $core;
            $core = static fn(Request $request): Response => $layer->handle($request, $next);
        }

        return $core($request);
    }
}
