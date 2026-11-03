<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\{Connection, QueryLog, TracedPdo, TracedStatement};
use App\Http\{Pipeline, Request, Response, Router};
use App\Middleware\{Debug, ErrorHandler, RequestId};
use App\Tests\DatabaseTestCase;
use PDO;
use RuntimeException;

final class DebugTest extends DatabaseTestCase
{
    /** @var array<string, mixed> */
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $_ENV['APP_DEBUG'] = '0';
        parent::setUp();
        QueryLog::$queries = [];
    }

    protected function tearDown(): void
    {
        try { parent::tearDown(); } finally {
            $_ENV = $this->environment;
            QueryLog::$queries = [];
        }
    }

    public function test_disabled_debug_and_production_use_plain_pdo_without_headers_or_queries(): void
    {
        foreach ([['local', '0'], ['test', '0'], ['production', '0'], ['production', '1']] as [$mode, $debug]) {
            $_ENV['APP_ENV'] = $mode;
            $_ENV['APP_DEBUG'] = $debug;
            $connection = new Connection();
            self::assertSame(PDO::class, $connection->pdo()::class);
            $response = $this->runDebug(static function () use ($connection): Response {
                ($connection->pdo()->query('SELECT 1') ?: throw new RuntimeException('Query failed'))->fetchAll();
                return Response::json(['status' => 'ok']);
            });
            self::assertArrayNotHasKey('Server-Timing', $response->headers);
            self::assertArrayNotHasKey('X-Debug-Queries', $response->headers);
            self::assertSame([], QueryLog::$queries);
        }
    }

    public function test_debug_counts_direct_and_prepared_executions_across_connections_without_parameters(): void
    {
        $_ENV['APP_DEBUG'] = '1';
        $pdo = (new Connection())->pdo();
        self::assertInstanceOf(TracedPdo::class, $pdo);
        $statement = $pdo->prepare('SELECT ? AS marker');
        self::assertInstanceOf(TracedStatement::class, $statement);
        self::assertSame([], QueryLog::$queries, 'Preparing a statement does not execute it');
        $secret = 'bound-value-must-not-be-recorded';
        $response = $this->runDebug(static function () use ($pdo, $statement, $secret): Response {
            $direct = $pdo->query('SELECT 1 AS value', PDO::FETCH_COLUMN, 0) ?: throw new RuntimeException('Query failed');
            self::assertSame(1, $direct->fetch());
            $direct->closeCursor();
            self::assertSame(0, $pdo->exec('SET @debug_probe = 1'));
            $statement->execute([$secret]);
            self::assertSame(['marker' => $secret], $statement->fetch());
            $statement->closeCursor();
            $statement->bindValue(1, $secret);
            $statement->execute();
            $statement->fetchAll();
            ((new Connection())->pdo()->query('SELECT 2') ?: throw new RuntimeException('Query failed'))->fetchAll();
            return Response::json(['status' => 'ok']);
        });
        self::assertSame('5', $response->headers['X-Debug-Queries']);
        self::assertSame(['SELECT 1 AS value', 'SET @debug_probe = 1', 'SELECT ? AS marker', 'SELECT ? AS marker', 'SELECT 2'], array_column(QueryLog::$queries, 'sql'));
        self::assertStringNotContainsString($secret, json_encode(QueryLog::$queries, JSON_THROW_ON_ERROR));
        self::assertMatchesRegularExpression('/\Atotal;dur=\d+\.\d{3}, db;dur=\d+\.\d{3};desc="5 queries", mem;desc="\d+\.\d{2} MB peak"\z/', $response->headers['Server-Timing']);
        self::assertStringContainsString('db;dur=' . sprintf('%.3F', array_sum(array_column(QueryLog::$queries, 'ms'))), $response->headers['Server-Timing']);
        self::assertSame('{"status":"ok"}', $response->body);
        $empty = $this->runDebug(static fn(): Response => Response::json(['status' => 'ok']));
        self::assertSame('0', $empty->headers['X-Debug-Queries']);
        self::assertSame([], QueryLog::$queries, 'Each request starts a new counter');
    }

    public function test_all_connection_helpers_are_counted_in_server_timing_without_parameter_values(): void
    {
        $_ENV['APP_DEBUG'] = '1';
        $connection = new Connection();
        $connection->pdo()->beginTransaction();
        $email = 'helper-trace@example.com';
        try {
            $response = $this->runDebug(static function () use ($connection, $email): Response {
                self::assertSame(1, $connection->run('INSERT INTO users (email, password_hash) VALUES (?, ?)', [$email, 'trace-secret']));
                self::assertSame(['email' => $email], $connection->one('SELECT email FROM users WHERE email = :email', ['email' => $email]));
                self::assertSame([['email' => $email]], $connection->all('SELECT email FROM users WHERE email = ?', [$email]));
                return Response::noContent();
            });
            self::assertSame('3', $response->headers['X-Debug-Queries']);
            self::assertStringContainsString('desc="3 queries"', $response->headers['Server-Timing']);
            self::assertCount(3, QueryLog::$queries);
            $log = json_encode(QueryLog::$queries, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($email, $log);
            self::assertStringNotContainsString('trace-secret', $log);
        } finally { $connection->pdo()->rollBack(); }
    }

    public function test_application_routes_report_the_actual_number_of_queries(): void
    {
        $_ENV['APP_DEBUG'] = '1';
        $_ENV['API_KEY_HASHES'] = 'debug:' . hash('sha256', 'debug-test-key');
        $connection = new Connection();
        $pdo = $connection->pdo();
        $pdo->beginTransaction();
        $router = new Router(require __DIR__ . '/../../src/routes.php', $connection);
        try {
            $requests = [
                [new Request('GET', '/health'), 200, '1'],
                [new Request('GET', '/users'), 401, '0'],
                [new Request('GET', '/users', headers: ['X-Api-Key' => 'debug-test-key']), 200, '1'],
                [new Request('POST', '/users', headers: ['X-Api-Key' => 'debug-test-key', 'Content-Type' => 'application/json'],
                    body: ['email' => 'debug-example@example.com', 'password' => bin2hex(random_bytes(16))]), 201, '2'],
            ];
            foreach ($requests as [$request, $status, $count]) {
                $response = $this->runDebug($router->handle(...), $request);
                self::assertSame($status, $response->status);
                self::assertSame($count, $response->headers['X-Debug-Queries']);
                self::assertSame((int) $count, count(QueryLog::$queries));
            }
        } finally { $pdo->rollBack(); }
    }

    public function test_failed_queries_are_counted_and_error_responses_keep_debug_headers(): void
    {
        $_ENV['APP_DEBUG'] = '1';
        $response = $this->runDebug(static function (): Response {
            (new Connection())->pdo()->query('SELECT * FROM missing_debug_table');
            return Response::noContent();
        });
        self::assertSame(500, $response->status);
        self::assertSame('1', $response->headers['X-Debug-Queries']);
        self::assertSame('SELECT * FROM missing_debug_table', QueryLog::$queries[0]['sql']);
        self::assertSame('internal_error', json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function test_slow_direct_and_prepared_queries_warn_without_parameter_values(): void
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $script = 'require ' . $autoload . ';' . <<<'PHP'
$_ENV = array_merge($_ENV, ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'DB_HOST' => 'db-test', 'DB_PORT' => '3306',
    'DB_NAME' => 'app_test', 'DB_USER' => 'test', 'DB_PASSWORD' => 'test-password']);
$pdo = (new App\Database\Connection())->pdo();
($pdo->query('SELECT SLEEP(0.12)') ?: throw new RuntimeException('Query failed'))->fetchAll();
$statement = $pdo->prepare('SELECT SLEEP(0.12), ? AS marker');
$statement->execute(['private-bound-value']);
$statement->fetchAll();
echo count(App\Database\QueryLog::$queries);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('2', $stdout);
        self::assertStringNotContainsString('private-bound-value', $stderr);
        $records = array_map(static fn(string $line): mixed => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($stderr)));
        self::assertCount(2, $records);
        foreach ($records as $record) {
            self::assertSame('warning', $record['level']);
            self::assertSame('Slow query', $record['message']);
            self::assertGreaterThan(100, $record['context']['ms']);
        }
        self::assertSame('SELECT SLEEP(0.12), ? AS marker', $records[1]['context']['sql']);
    }

    /** @param callable(Request):Response $core */
    private function runDebug(callable $core, ?Request $request = null): Response
    {
        return Pipeline::run([new Debug(), new ErrorHandler(), new RequestId()], $request ?? new Request('GET', '/example'), $core);
    }
}
