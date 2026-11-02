<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\Connection;
use App\Http\Middleware;
use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Middleware\AccessLog;
use App\Middleware\ErrorHandler;
use App\Middleware\RequestId;
use App\Tests\DatabaseTestCase;
use RuntimeException;

final class RouterTest extends DatabaseTestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router([
            'GET /example' => [RouterTestController::class, 'respond'],
            'POST /example' => [RouterTestController::class, 'respond'],
            'GET /middleware' => [RouterTestController::class, 'respond', [FirstRouteLayer::class, SecondRouteLayer::class]],
            'GET /protected' => [UnreachableController::class, 'respond', [RejectRouteLayer::class]],
            'GET /failure' => [FailingController::class, 'respond'],
            'GET /http-failure' => [HttpFailureController::class, 'respond'],
        ], $this->connection);
    }

    public function test_exact_route_receives_request_and_the_connection_instance(): void
    {
        $response = $this->router->handle(new Request('POST', '/example/', ['id' => '7'], body: ['email' => 'a@b.com']));
        $body = json_decode($response->body, true);

        self::assertSame(200, $response->status);
        self::assertSame('POST', $body['method']);
        self::assertSame(['id' => '7'], $body['query']);
        self::assertSame(['email' => 'a@b.com'], $body['body']);
        self::assertSame(spl_object_id($this->connection), $body['connection_id']);
    }

    public function test_unknown_path_returns_uniform_404(): void
    {
        $response = $this->router->handle(new Request('GET', '/example/extra'));

        self::assertSame(404, $response->status);
        self::assertSame('{"error":{"code":"not_found","message":"Not found","details":{}}}', $response->body);
    }

    public function test_wrong_method_returns_all_allowed_methods(): void
    {
        $response = $this->router->handle(new Request('DELETE', '/example'));

        self::assertSame(405, $response->status);
        self::assertSame('GET, POST', $response->headers['Allow']);
        self::assertSame('method_not_allowed', json_decode($response->body, true)['error']['code']);
    }

    public function test_routes_do_not_imply_head_or_options_handlers(): void
    {
        foreach (['HEAD', 'OPTIONS'] as $method) {
            self::assertSame(405, $this->router->handle(new Request($method, '/example'))->status);
        }
    }

    public function test_route_middleware_order_and_attribute_copy(): void
    {
        $request = new Request('GET', '/middleware');
        $response = $this->router->handle($request);

        self::assertSame(['first', 'second'], json_decode($response->body, true)['attributes']['trace']);
        self::assertSame('second,first', $response->headers['X-Trace']);
        self::assertSame([], $request->attributes);
    }

    public function test_route_middleware_can_stop_before_controller_construction(): void
    {
        self::assertSame(401, $this->router->handle(new Request('GET', '/protected'))->status);
    }

    public function test_application_routes_return_json_404_405_and_public_health(): void
    {
        $router = new Router(require __DIR__ . '/../../src/routes.php', $this->connection);
        $response = $this->handle($router, new Request('POST', '/health'));
        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow']);
        self::assertSame('method_not_allowed', json_decode($response->body, true)['error']['code']);
        $response = $this->handle($router, new Request('GET', '/nope'));
        self::assertSame(404, $response->status);
        self::assertSame('not_found', json_decode($response->body, true)['error']['code']);
        self::assertSame($response->headers['X-Request-Id'], json_decode($response->body, true)['error']['request_id']);
        self::assertSame(404, $this->handle($router, new Request('POST', '/users/create'))->status);
        $response = $this->handle($router, new Request('GET', '/health'));
        self::assertSame(200, $response->status);
        self::assertSame('{"status":"ok"}', $response->body);
    }

    public function test_controller_failure_returns_500_without_message_or_trace(): void
    {
        $environment = $_ENV;
        $_ENV['APP_DEBUG'] = '0';
        try {
            $response = $this->handle($this->router, new Request('GET', '/failure', headers: ['X-Request-Id' => 'failure-test-123']));
            self::assertSame(500, $response->status);
            self::assertSame('{"error":{"code":"internal_error","message":"Internal Server Error","details":{},"request_id":"failure-test-123"}}', $response->body);
            self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
            self::assertSame('failure-test-123', $response->headers['X-Request-Id']);
            self::assertStringNotContainsString('private-controller-message', $response->body);
            self::assertStringNotContainsString('FailingController', $response->body);
        } finally {
            $_ENV = $environment;
        }
    }

    public function test_controller_http_exception_keeps_status_public_details_and_request_id(): void
    {
        $response = $this->handle($this->router, new Request('GET', '/http-failure', headers: ['X-Request-Id' => 'http-failure-123']));
        $body = json_decode($response->body, true);
        self::assertSame(404, $response->status);
        self::assertSame('example_missing', $body['error']['code']);
        self::assertSame(['resource' => 'example'], $body['error']['details']);
        self::assertSame('http-failure-123', $body['error']['request_id']);
        self::assertSame('1', $response->headers['X-Example']);
    }

    public function test_controller_failure_in_debug_contains_only_safe_diagnostics(): void
    {
        $environment = $_ENV;
        $_ENV['APP_DEBUG'] = '1';
        try {
            $response = $this->handle($this->router, new Request('GET', '/failure', headers: ['X-Request-Id' => 'debug-failure-123']));
            $body = json_decode($response->body, true);
            self::assertSame(500, $response->status);
            self::assertSame('Internal Server Error', $body['error']['message']);
            self::assertSame('debug-failure-123', $body['error']['request_id']);
            self::assertSame('RuntimeException', $body['error']['details']['class']);
            self::assertSame('private-controller-message', $body['error']['details']['message']);
            self::assertMatchesRegularExpression('/:\\d+$/', $body['error']['details']['file']);
            self::assertIsArray($body['error']['details']['trace']);
            foreach ($body['error']['details']['trace'] as $frame) {
                self::assertArrayNotHasKey('args', $frame);
                self::assertArrayNotHasKey('object', $frame);
            }
        } finally {
            $_ENV = $environment;
        }
    }

    public function test_health_returns_down_when_connection_cannot_be_created(): void
    {
        $environment = $_ENV;
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_PORT'] = '1';
        try {
            $router = new Router(require __DIR__ . '/../../src/routes.php', new Connection());
            $response = $this->handle($router, new Request('GET', '/health'));

            self::assertSame(503, $response->status);
            self::assertSame('{"status":"down"}', $response->body);
        } finally {
            $_ENV = $environment;
        }
    }

    private function handle(Router $router, Request $request): Response
    {
        return Pipeline::run([new ErrorHandler(), new RequestId(), new AccessLog()], $request, $router->handle(...));
    }

}

final class RouterTestController
{
    public function __construct(private Connection $connection)
    {
    }

    public function respond(Request $request): Response
    {
        return Response::json([
            'method' => $request->method,
            'query' => $request->query,
            'body' => $request->body,
            'attributes' => $request->attributes,
            'connection_id' => spl_object_id($this->connection),
        ]);
    }
}

final class FirstRouteLayer implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request->withAttribute('trace', ['first']));

        return $response->withHeader('X-Trace', $response->headers['X-Trace'] . ',first');
    }
}

final class SecondRouteLayer implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request->withAttribute('trace', [...$request->attributes['trace'], 'second']))->withHeader('X-Trace', 'second');
    }
}

final class RejectRouteLayer implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return Response::error(401, 'unauthorized', 'Unauthorized');
    }
}

final class UnreachableController
{
    public function __construct(Connection $_connection)
    {
        throw new RuntimeException('Controller must not be constructed before middleware');
    }
}

final class FailingController
{
    public function __construct(Connection $_connection)
    {
    }

    public function respond(Request $request): Response
    {
        throw new RuntimeException('private-controller-message');
    }
}

final class HttpFailureController
{
    public function __construct(Connection $_connection)
    {
    }

    public function respond(Request $request): Response
    {
        throw new \App\Http\HttpException(404, 'example_missing', 'Resource missing', ['resource' => 'example'], ['X-Example' => '1']);
    }
}
