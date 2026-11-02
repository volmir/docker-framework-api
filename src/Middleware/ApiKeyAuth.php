<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Config;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Log\Logger;
use RuntimeException;

final class ApiKeyAuth implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        try { $hashes = Config::apiKeyHashes(); } catch (RuntimeException) {
            $hashes = [];
            Logger::warning('API_KEY_HASHES is empty or invalid; denying API key authentication');
        }
        $key = $request->header('X-Api-Key') ?? '';
        if ($key === '' && preg_match('/\ABearer[ \t]+(\S+)\z/i', trim($request->header('Authorization') ?? ''), $match)) {
            $key = $match[1];
        }
        $digest = hash('sha256', $key);
        $client = null;
        foreach ($hashes as [$name, $expected]) {
            $matched = hash_equals($expected, $digest);
            if ($matched && $client === null) { $client = $name; }
        }
        if ($key === '' || $client === null) {
            return Response::error(401, 'unauthorized', 'Valid API key required')->withHeader('WWW-Authenticate', 'ApiKey');
        }
        $context = $request->attributes['access_log'] ?? null;
        if ($context !== null) { $context->client = $client; }
        return $next($request->withAttribute('client', $client));
    }
}
