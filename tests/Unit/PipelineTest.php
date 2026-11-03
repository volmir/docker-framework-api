<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Middleware;
use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Response;
use Error;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    public function test_first_middleware_wraps_the_whole_pipeline(): void
    {
        $layers = [new TraceMiddleware('outer'), new TraceMiddleware('inner')];
        $request = new Request('GET', '/');
        $core = static fn(Request $request): Response => Response::json($request->attributes);

        self::assertSame('{"trace":["outer","inner"]}', Pipeline::run($layers, $request, $core)->body);
        self::assertSame('inner,outer', Pipeline::run($layers, $request, $core)->headers['X-Trace']);
        self::assertSame('[]', Pipeline::run([], $request, $core)->body);
    }

    public function test_middleware_can_short_circuit_without_calling_core(): void
    {
        $layer = new class implements Middleware {
            public function handle(Request $request, callable $next): Response
            {
                return Response::error(401, 'unauthorized', 'Unauthorized');
            }
        };
        $response = Pipeline::run([$layer], new Request('GET', '/'), static fn(): never => throw new Error('Core must not run'));

        self::assertSame(401, $response->status);
    }
}

final class TraceMiddleware implements Middleware
{
    public function __construct(private string $name) {}

    public function handle(Request $request, callable $next): Response
    {
        $trace = [...($request->attributes['trace'] ?? []), $this->name];
        $response = $next($request->withAttribute('trace', $trace));

        $previous = $response->headers['X-Trace'] ?? '';

        return $response->withHeader('X-Trace', ($previous === '' ? '' : $previous . ',') . $this->name);
    }
}
