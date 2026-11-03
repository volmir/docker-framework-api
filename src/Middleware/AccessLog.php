<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\HttpException;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Log\Logger;

final class AccessLog implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $start = hrtime(true);
        $status = 500;
        // Shared log context survives immutable request copies made by route middleware.
        $context = (object) array_intersect_key($request->attributes, ['client' => true]);
        $request = $request->withAttribute('access_log', $context);
        try {
            $response = $next($request);
            $status = $response->status;
            return $response;
        } catch (HttpException $error) {
            $status = $error->status;
            throw $error;
        } finally {
            Logger::write([
                'request_id' => $request->attributes['request_id'] ?? null,
                'method' => $request->method,
                'path' => $request->path,
                'status' => $status,
                'ms' => round((hrtime(true) - $start) / 1_000_000, 2),
            ] + (isset($context->client) ? ['client' => $context->client] : []));
        }
    }
}
