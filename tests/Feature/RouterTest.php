<?php
declare(strict_types=1);

namespace App\Controller {
    use App\Http\Request;

    final class RouterTestController
    {
        public function __construct(private \PDO $pdo)
        {
        }

        public function plain(Request $request): string
        {
            return 'not a response';
        }
    }
}

namespace App\Tests\Feature {
    use App\Http\Request;
    use App\Http\Router;
    use PDO;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class RouterTest extends TestCase
    {
        private PDO $pdo;
        private array $routes = [
            '/health' => 'HealthController/index',
            '/users' => 'UserController/index',
            '/users/create' => 'UserController/create',
            '/users/show' => 'UserController/show',
        ];

        protected function setUp(): void
        {
            $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, created_at TEXT)');
        }

        public function test_health_route_returns_ok(): void
        {
            $response = $this->dispatch('GET', '/health');

            self::assertSame(200, $response->status);
            self::assertSame('{"status":"ok"}', $response->body);
        }

        public function test_users_index_returns_empty_data(): void
        {
            $response = $this->dispatch('GET', '/users');

            self::assertSame(200, $response->status);
            self::assertSame('{"data":[]}', $response->body);
        }

        public function test_show_returns_existing_user(): void
        {
            $this->pdo->exec("INSERT INTO users (id, email, password_hash, created_at) VALUES (1, 'a@b.com', 'hash', 'now')");

            $response = $this->dispatch('GET', '/users/show', ['id' => '1']);

            self::assertSame(200, $response->status);
            self::assertSame('a@b.com', json_decode($response->body, true)['email']);
        }

        public function test_show_returns_404_for_missing_user(): void
        {
            $response = $this->dispatch('GET', '/users/show', ['id' => '1']);

            self::assertSame(404, $response->status);
            self::assertSame('{"error":"Not Found"}', $response->body);
        }

        public function test_show_requires_numeric_id(): void
        {
            self::assertSame(400, $this->dispatch('GET', '/users/show')->status);
            self::assertSame(400, $this->dispatch('GET', '/users/show', ['id' => 'abc'])->status);
        }

        public function test_create_rejects_get_with_allow_header(): void
        {
            $response = $this->dispatch('GET', '/users/create');

            self::assertSame(405, $response->status);
            self::assertSame('POST', $response->headers['Allow']);
        }

        public function test_create_inserts_user(): void
        {
            $response = $this->dispatch('POST', '/users/create', [], ['email' => 'a@b.com', 'password' => 'secret']);

            self::assertSame(201, $response->status);
            self::assertGreaterThan(0, json_decode($response->body, true)['id']);
        }

        public function test_create_rejects_invalid_email(): void
        {
            $response = $this->dispatch('POST', '/users/create', [], ['email' => 'not-email']);

            self::assertSame(400, $response->status);
        }

        public function test_unknown_path_returns_404_json(): void
        {
            $response = $this->dispatch('GET', '/unknown');

            self::assertSame(404, $response->status);
            self::assertSame('{"error":"Not Found"}', $response->body);
        }

        public function test_invalid_route_target_throws(): void
        {
            $this->expectException(RuntimeException::class);
            (new Router(['/broken' => 'Users'], $this->pdo))->dispatch(new Request('GET', '/broken', [], []));
        }

        public function test_missing_controller_throws(): void
        {
            $this->expectException(RuntimeException::class);
            (new Router(['/missing' => 'Missing/x'], $this->pdo))->dispatch(new Request('GET', '/missing', [], []));
        }

        public function test_non_response_result_returns_500_json(): void
        {
            $router = new Router(['/plain' => 'RouterTestController/plain'], $this->pdo);
            $response = $router->dispatch(new Request('GET', '/plain', [], []));

            self::assertSame(500, $response->status);
            self::assertSame('{"error":"Controller returned string"}', $response->body);
        }

        private function dispatch(string $method, string $path, array $query = [], array $body = []): \App\Http\Response
        {
            return (new Router($this->routes, $this->pdo))->dispatch(new Request($method, $path, $query, $body));
        }
    }
}
