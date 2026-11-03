<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Middleware\AccessLog;
use App\Middleware\ErrorHandler;
use App\Middleware\RequestId;
use App\Tests\DatabaseTestCase;

final class UserControllerTest extends DatabaseTestCase
{
    private const string KEY = 'feature-test-key';
    /** @var array<string, mixed> */
    private array $environment;
    private Router $router;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        parent::setUp();
        $_ENV['API_KEYS'] = 'test:' . self::KEY;
        $_ENV['APP_DEBUG'] = '0';
        $this->router = new Router(require __DIR__ . '/../../src/routes.php', $this->connection);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
        parent::tearDown();
    }

    public function test_users_require_a_key_and_accept_bearer_authentication(): void
    {
        foreach ([['GET', '/users'], ['GET', '/users/show'], ['POST', '/users']] as [$method, $path]) {
            $response = $this->request($method, $path, headers: []);
            self::assertSame(401, $response->status);
            self::assertSame('ApiKey', $response->headers['WWW-Authenticate']);
        }
        $response = $this->request('GET', '/users', headers: ['Authorization' => 'Bearer ' . self::KEY]);
        self::assertSame(200, $response->status);
        self::assertSame(['data' => [], 'meta' => ['limit' => 20, 'offset' => 0]], $this->json($response));
    }

    public function test_create_hashes_password_and_returns_location_without_hash(): void
    {
        foreach ([8, 256] as $length) {
            $password = str_repeat('я', $length);
            $response = $this->request('POST', '/users', body: [
                'email' => "length{$length}@example.com", 'password' => $password, 'id' => -1, 'password_hash' => 'ignored',
            ]);
            $user = $this->json($response)['data'];
            self::assertSame(201, $response->status);
            self::assertSame('/users/show?id=' . $user['id'], $response->headers['Location']);
            self::assertSame(['id', 'email', 'created_at'], array_keys($user));
            $stored = ($this->pdo->query('SELECT * FROM users WHERE id = ' . $user['id']) ?: self::fail('Database query failed'))->fetch();
            self::assertTrue(password_verify($password, $stored['password_hash']));
            self::assertSame('argon2id', password_get_info($stored['password_hash'])['algoName']);
            self::assertNotEmpty($stored['created_at']);
            self::assertNotEmpty($stored['updated_at']);
            $shown = $this->request('GET', '/users/show', ['id' => (string) $user['id']]);
            self::assertSame(200, $shown->status);
            self::assertSame($user, $this->json($shown));
        }
    }

    public function test_validation_is_422_with_field_details_and_does_not_insert(): void
    {
        foreach ([[], ['email' => [], 'password' => 42], ['email' => 'bad', 'password' => ''],
            ['email' => str_repeat('a', 256) . '@example.com', 'password' => str_repeat('я', 257)],
            ['email' => 'valid@example.com', 'password' => str_repeat('x', 7)]] as $body) {
            $response = $this->request('POST', '/users', body: $body);
            self::assertSame(422, $response->status);
            $error = $this->json($response)['error'];
            self::assertSame('validation_failed', $error['code']);
            self::assertArrayHasKey('password', $error['details']);
            self::assertIsArray($error['details']['password']);
            self::assertIsString($error['details']['password'][0]);
            if (($body['email'] ?? null) !== 'valid@example.com') {
                self::assertArrayHasKey('email', $error['details']);
            }
        }
        self::assertSame(0, (int) ($this->pdo->query('SELECT COUNT(*) FROM users') ?: self::fail('Database query failed'))->fetchColumn());
    }

    public function test_duplicate_email_is_409_and_keeps_one_user(): void
    {
        $body = ['email' => 'duplicate@example.com', 'password' => bin2hex(random_bytes(16))];
        self::assertSame(201, $this->request('POST', '/users', body: $body)->status);
        $response = $this->request('POST', '/users', body: $body);
        self::assertSame(409, $response->status);
        self::assertSame('email_taken', $this->json($response)['error']['code']);
        self::assertSame(1, (int) ($this->pdo->query('SELECT COUNT(*) FROM users') ?: self::fail('Database query failed'))->fetchColumn());
    }

    public function test_create_requires_json_after_authentication(): void
    {
        foreach ([['X-Api-Key' => self::KEY], ['X-Api-Key' => self::KEY, 'Content-Type' => 'text/plain']] as $headers) {
            $response = $this->request('POST', '/users', headers: $headers);
            self::assertSame(415, $response->status);
            self::assertSame('unsupported_media_type', $this->json($response)['error']['code']);
        }
        self::assertSame(0, (int) ($this->pdo->query('SELECT COUNT(*) FROM users') ?: self::fail('Database query failed'))->fetchColumn());
    }

    public function test_pagination_has_defaults_bounds_offset_and_never_exposes_hashes(): void
    {
        $statement = $this->pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $ids = [];
        for ($i = 0; $i < 105; $i++) {
            $statement->execute(["user{$i}@example.com", 'test-hash']);
            $ids[] = (int) $this->pdo->lastInsertId();
        }
        foreach ([[[], 20, 0, 20], [['limit' => null, 'offset' => null], 20, 0, 20],
            [['limit' => '5', 'offset' => '10'], 5, 10, 5],
            [['limit' => '100', 'offset' => '3', 'extra' => 'ignored'], 100, 3, 100],
            [['limit' => '2', 'offset' => '104'], 2, 104, 1], [['offset' => '200'], 20, 200, 0]] as [$query, $limit, $offset, $count]) {
            $response = $this->request('GET', '/users', $query);
            self::assertSame(200, $response->status);
            $body = $this->json($response);
            self::assertSame(['limit' => $limit, 'offset' => $offset], $body['meta']);
            self::assertCount($count, $body['data']);
            if ($count > 0) {
                self::assertSame($ids[$offset], $body['data'][0]['id']);
            }
            foreach ($body['data'] as $user) {
                self::assertSame(['id', 'email', 'created_at'], array_keys($user));
            }
        }
    }

    public function test_show_rejects_invalid_ids_and_returns_404_for_a_missing_user(): void
    {
        foreach ([['id' => []], ['id' => '1.5'], ['id' => 'abc'], [], ['id' => '0'], ['id' => '-1']] as $query) {
            $response = $this->request('GET', '/users/show', $query);
            self::assertSame(422, $response->status);
            $error = $this->json($response)['error'];
            self::assertSame('validation_failed', $error['code']);
            self::assertIsArray($error['details']['id']);
            self::assertIsString($error['details']['id'][0]);
        }
        $response = $this->request('GET', '/users/show', ['id' => '999999']);
        self::assertSame(404, $response->status);
        self::assertSame('not_found', $this->json($response)['error']['code']);
    }

    public function test_invalid_pagination_returns_422_with_field_details(): void
    {
        foreach ([['limit' => '0'], ['limit' => '101'], ['limit' => 'x'], ['offset' => '-1'], ['offset' => []]] as $query) {
            $response = $this->request('GET', '/users', $query);
            self::assertSame(422, $response->status);
            self::assertArrayHasKey(array_key_first($query), $this->json($response)['error']['details']);
        }
        $response = $this->request('GET', '/users', ['limit' => '101', 'offset' => '-1']);
        self::assertSame(422, $response->status);
        self::assertSame(['limit', 'offset'], array_keys($this->json($response)['error']['details']));
    }

    /** @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string>|null $headers */
    private function request(string $method, string $path, array $query = [], array $body = [], ?array $headers = null): Response
    {
        $headers ??= ['X-Api-Key' => self::KEY, 'Content-Type' => 'application/json'];
        $response = Pipeline::run([new ErrorHandler(), new RequestId(), new AccessLog()],
            new Request($method, $path, $query, $headers, $body), $this->router->handle(...));
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{16}\z/', $response->headers['X-Request-Id']);
        return $response;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
    }
}
