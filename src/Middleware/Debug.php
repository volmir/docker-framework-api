<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config;
use App\Database\QueryLog;
use App\Http\{Middleware, Request, Response};

final class Debug implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Config::isDebug()) {
            return $next($request);
        }
        QueryLog::$queries = [];
        $start = hrtime(true);
        $response = $next($request);
        $timing = sprintf('total;dur=%.3F, db;dur=%.3F;desc="%d queries", mem;desc="%.2F MB peak"', (hrtime(true) - $start) / 1_000_000, array_sum(array_column(QueryLog::$queries, 'ms')), count(QueryLog::$queries), memory_get_peak_usage(true) / 1_048_576);
        return $response->withHeader('Server-Timing', $timing)->withHeader('X-Debug-Queries', (string) count(QueryLog::$queries));
    }
}
