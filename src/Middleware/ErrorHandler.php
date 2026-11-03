<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config;
use App\Http\{HttpException, Middleware, Request, Response};
use App\Log\{Logger, Redactor};
use Throwable;

final class ErrorHandler implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $error) {
            return self::response($error, $request);
        }
    }

    public static function response(Throwable $error, ?Request $request = null): Response
    {
        $id = $request?->attributes['request_id'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? null);
        try {
            $debug = Config::isDebug();
        } catch (\RuntimeException) {
            $debug = false;
        }
        $context = ['class' => $error::class, 'message' => $error->getMessage(), 'file' => $error->getFile() . ':' . $error->getLine(), 'request_id' => $id];
        if ($debug) {
            $context['trace'] = array_map(static fn(array $frame): array => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'type', 'function'])), $error->getTrace());
        }
        $context = Redactor::clean($context, $request);
        Logger::error('Application error', $context);
        $http = $error instanceof HttpException;
        $details = $http ? Redactor::clean($error->details, $request) : [];
        $details = $debug ? array_merge($details, array_diff_key($context, ['request_id' => true])) : $details;
        $response = Response::error($http ? $error->status : 500, $http ? $error->errorCode : 'internal_error', $http ? Redactor::clean($error->getMessage(), $request) : 'Internal Server Error', $details);
        foreach ($http ? $error->headers : [] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $id === null ? $response : $response->withRequestId($id);
    }
}
