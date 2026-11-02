<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;

final class RequireJson implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $type = strtolower(trim(explode(';', $request->header('Content-Type') ?? '', 2)[0]));
        if (in_array($request->method, ['POST', 'PUT', 'PATCH'], true) && $type !== 'application/json') {
            return Response::error(415, 'unsupported_media_type', 'Content-Type must be application/json');
        }
        return $next($request);
    }
}
