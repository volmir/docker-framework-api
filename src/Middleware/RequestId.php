<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use Throwable;

final class RequestId implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $id = self::resolve($request->header('X-Request-Id') ?? '');
        $request = $request->withAttribute('request_id', $id);
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            $response = ErrorHandler::response($error, $request);
        }
        return $response->withRequestId($id);
    }

    public static function resolve(string $candidate): string
    {
        return preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $candidate) ? $candidate : bin2hex(random_bytes(8));
    }
}
