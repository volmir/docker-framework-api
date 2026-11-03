<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\HttpException;
use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Response;
use App\Middleware\ApiKeyAuth;
use App\Middleware\ErrorHandler;
use App\Middleware\RequestId;
use App\Middleware\RequireJson;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $_ENV['APP_DEBUG'] = '0';
        $_ENV['API_KEYS'] = 'test:test-key';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
    }

    public function test_http_exception_status_details_and_headers_are_preserved(): void
    {
        $response = Pipeline::run([new ErrorHandler()], new Request('GET', '/'), static fn(): never => throw new HttpException(429, 'limited', 'Try later', ['retry' => 60], ['Retry-After' => '60']));

        self::assertSame(429, $response->status);
        self::assertSame('60', $response->headers['Retry-After']);
        self::assertSame('{"error":{"code":"limited","message":"Try later","details":{"retry":60}}}', $response->body);
    }

    public function test_unexpected_failures_log_context_but_hide_message_and_trace_without_debug(): void
    {
        [$output, $records] = $this->captureStderr(<<<'PHP'
$results = [];
foreach (['0', '1', 'true', 'invalid'] as $debug) {
    $_ENV['APP_DEBUG'] = $debug;
    $response = App\Middleware\ErrorHandler::response(new RuntimeException('private-password'));
    $results[$debug] = ['status' => $response->status, 'body' => json_decode($response->body, true)];
}
echo json_encode($results, JSON_THROW_ON_ERROR);
PHP);
        $results = json_decode($output, true);
        foreach (['0' => false, '1' => true, 'true' => true, 'invalid' => false] as $debug => $exposed) {
            $result = $results[$debug];
            self::assertSame(500, $result['status']);
            self::assertSame('internal_error', $result['body']['error']['code']);
            self::assertSame('Internal Server Error', $result['body']['error']['message']);
            if ($exposed) {
                self::assertSame('RuntimeException', $result['body']['error']['details']['class']);
                self::assertSame('private-password', $result['body']['error']['details']['message']);
                self::assertIsArray($result['body']['error']['details']['trace']);
                self::assertArrayHasKey('file', $result['body']['error']['details']);
            } else {
                self::assertSame([], $result['body']['error']['details']);
            }
            self::assertSame($exposed, isset($result['body']['error']['details']['trace']));
        }
        self::assertCount(4, $records);
        foreach ($records as $index => $record) {
            self::assertSame('error', $record['level']);
            self::assertSame('Application error', $record['message']);
            self::assertSame('private-password', $record['context']['message']);
            self::assertSame('RuntimeException', $record['context']['class']);
            self::assertArrayHasKey('request_id', $record['context']);
            self::assertArrayHasKey('file', $record['context']);
            self::assertSame(in_array($index, [1, 2], true), isset($record['context']['trace']));
        }
    }

    public function test_request_id_reaches_core_and_response_without_mutating_original(): void
    {
        foreach (['client-123', str_repeat('a', 8), str_repeat('B', 64)] as $id) {
            $request = new Request('GET', '/', headers: ['X-Request-ID' => $id]);
            $response = (new RequestId())->handle($request, static fn(Request $request): Response => Response::json($request->attributes));

            self::assertSame($id, $response->headers['X-Request-Id']);
            self::assertSame(['request_id' => $id], json_decode($response->body, true));
            self::assertSame([], $request->attributes);
        }
    }

    public function test_invalid_request_ids_are_replaced_with_sixteen_hex_characters(): void
    {
        foreach (['', str_repeat('x', 7), str_repeat('x', 65), 'invalid_id', 'invalid.id', "valid-123\n"] as $id) {
            $response = (new RequestId())->handle(new Request('GET', '/', headers: ['x-request-id' => $id]), static fn(): Response => Response::noContent());

            self::assertMatchesRegularExpression('/\A[a-f0-9]{16}\z/', $response->headers['X-Request-Id']);
            self::assertSame(204, $response->status);
        }
    }

    public function test_request_id_is_preserved_on_an_exception_response(): void
    {
        $request = new Request('GET', '/', headers: ['X-Request-Id' => 'client-123']);
        $response = Pipeline::run([new ErrorHandler(), new RequestId()], $request, static fn(): never => throw new HttpException(409, 'conflict', 'Conflict'));

        self::assertSame(409, $response->status);
        self::assertSame('client-123', $response->headers['X-Request-Id']);
        self::assertSame('client-123', json_decode($response->body, true)['error']['request_id']);
    }

    public function test_access_log_is_one_flat_json_line_even_when_the_core_throws(): void
    {
        [$output, $records] = $this->captureStderr(<<<'PHP'
$request = new App\Http\Request('POST', '/users', ['token' => 'secret-query'], body: ['password' => 'secret-body']);
$response = App\Http\Pipeline::run([new App\Middleware\ErrorHandler(), new App\Middleware\RequestId(), new App\Middleware\AccessLog()], $request, static fn(): never => throw new App\Http\HttpException(409, 'conflict', 'Conflict'));
echo json_encode(['status' => $response->status, 'request_id' => $response->headers['X-Request-Id']]);
PHP);
        $response = json_decode($output, true);
        self::assertCount(2, $records);
        $entry = $records[0];
        self::assertSame('error', $records[1]['level']);
        self::assertSame(['ts', 'request_id', 'method', 'path', 'status', 'ms'], array_keys($entry));
        self::assertNotFalse(strtotime($entry['ts']));
        self::assertSame('POST', $entry['method']);
        self::assertSame('/users', $entry['path']);
        self::assertSame(409, $entry['status']);
        self::assertSame($response['status'], $entry['status']);
        self::assertSame($response['request_id'], $entry['request_id']);
        self::assertGreaterThanOrEqual(0, $entry['ms']);
        self::assertStringNotContainsString('secret', json_encode($entry, JSON_THROW_ON_ERROR));
    }

    public function test_logger_writes_all_three_levels_with_json_context_to_stderr(): void
    {
        [$output, $records] = $this->captureStderr(<<<'PHP'
App\Log\Logger::info('event', ['path' => '/привет']);
App\Log\Logger::warning('warning');
App\Log\Logger::error('failure', ['cause' => 'example']);
PHP);
        self::assertSame('', $output);
        self::assertSame(['info', 'warning', 'error'], array_column($records, 'level'));
        self::assertSame(['path' => '/привет'], $records[0]['context']);
        self::assertSame(['cause' => 'example'], $records[2]['context']);
    }

    public function test_clients_and_rotated_keys_work_in_both_supported_headers(): void
    {
        $keys = ['mobile-old-key' => 'mobile', 'admin-key' => 'admin', 'mobile-new-key' => 'mobile'];
        $_ENV['API_KEYS'] = implode(', ', array_map(
            static fn(string $key, string $client): string => $client . ':' . $key,
            array_keys($keys),
            array_values($keys),
        ));
        foreach ($keys as $key => $client) {
            foreach ([['X-Api-Key' => $key], ['Authorization' => 'Bearer ' . $key], ['Authorization' => 'bearer ' . $key]] as $headers) {
                $request = new Request('GET', '/users', headers: $headers);
                $response = (new ApiKeyAuth())->handle($request, static fn(Request $request): Response => Response::json($request->attributes));
                self::assertSame(200, $response->status);
                self::assertSame($client, json_decode($response->body, true)['client']);
                self::assertSame([], $request->attributes);
            }
        }
    }

    public function test_missing_or_wrong_keys_deny_access_before_core_and_include_a_challenge(): void
    {
        $denied = [];
        foreach ([[], ['X-Api-Key' => 'wrong'], ['Authorization' => 'Basic test-key'], ['Authorization' => 'Bearer wrong'], ['X-Api-Key' => 'wrong', 'Authorization' => 'Bearer test-key']] as $headers) {
            $response = (new ApiKeyAuth())->handle(new Request('GET', '/users', headers: $headers), static fn(): never => throw new \LogicException('Core must not run'));

            self::assertSame(401, $response->status);
            self::assertSame('ApiKey', $response->headers['WWW-Authenticate']);
            self::assertSame('unauthorized', json_decode($response->body, true)['error']['code']);
            $denied[] = $response->body;
        }
        self::assertCount(1, array_unique($denied));
        $_ENV['API_KEYS'] = 'test:';
        self::assertSame(401, (new ApiKeyAuth())->handle(new Request('GET', '/users'), static fn(): Response => Response::noContent())->status);
    }

    public function test_api_keys_are_compared_directly_and_case_sensitively(): void
    {
        $_ENV['API_KEYS'] = 'mobile:Plaintext_Key-Case';
        foreach (['Plaintext_Key-Case' => 204, 'plaintext_key-case' => 401, hash('sha256', 'Plaintext_Key-Case') => 401] as $key => $status) {
            $response = (new ApiKeyAuth())->handle(new Request('GET', '/users', headers: ['X-Api-Key' => $key]), static fn(): Response => Response::noContent());
            self::assertSame($status, $response->status);
        }
    }

    public function test_configured_plaintext_keys_are_redacted_without_request_headers(): void
    {
        [$output, $records] = $this->captureStderr(<<<'PHP'
$_ENV['API_KEYS'] = 'mobile:Configured_Secret-One,admin:Configured_Secret-Two';
App\Log\Logger::error('Configured_Secret-One Configured_Secret-Two mobile:Configured_Secret-One');
PHP);
        self::assertSame('', $output);
        $logs = json_encode($records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Configured_Secret-One', $logs);
        self::assertStringNotContainsString('Configured_Secret-Two', $logs);
    }

    public function test_missing_or_empty_key_configuration_denies_everyone_and_logs_a_warning(): void
    {
        [$output, $records] = $this->captureStderr(<<<'PHP'
$statuses = [];
foreach (['', ', ,', null] as $config) {
    if ($config === null) {
        unset($_ENV['API_KEYS']);
        putenv('API_KEYS');
    } else {
        $_ENV['API_KEYS'] = $config;
    }
    $response = (new App\Middleware\ApiKeyAuth())->handle(new App\Http\Request('GET', '/users', headers: ['X-Api-Key' => 'private-key']), static fn(): never => throw new LogicException('Core must not run'));
    $statuses[] = $response->status;
}
echo json_encode($statuses, JSON_THROW_ON_ERROR);
PHP);
        self::assertSame([401, 401, 401], json_decode($output, true));
        self::assertSame(['warning', 'warning', 'warning'], array_column($records, 'level'));
        self::assertStringNotContainsString('private-key', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function test_one_malformed_entry_rejects_the_entire_list_and_logs_no_key(): void
    {
        [$output, $records] = $this->captureStderr(<<<'PHP'
$key = 'private-valid-key';
$statuses = [];
foreach ([$key, 'mobile:', ':'.$key, 'mobile:invalid key', 'bad client:'.$key,
    'mobile:key with spaces', 'mobile:'.$key.',', 'mobile:'.$key.',broken',
    str_repeat('a',65).':'.$key, 'mobile:'.$key.':extra'] as $config) {
    $_ENV['API_KEYS'] = $config;
    $response = (new App\Middleware\ApiKeyAuth())->handle(new App\Http\Request('GET', '/users', headers: ['X-Api-Key' => 'private-valid-key']),
        static fn(): never => throw new LogicException('Core must not run'));
    $statuses[] = $response->status;
}
echo json_encode($statuses, JSON_THROW_ON_ERROR);
PHP);
        self::assertSame(array_fill(0, 10, 401), json_decode($output, true));
        self::assertSame(array_fill(0, 10, 'warning'), array_column($records, 'level'));
        self::assertStringNotContainsString('private-valid-key', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function test_global_access_log_records_authenticated_client_on_success_and_failure(): void
    {
        [$output, $records] = $this->captureStderr(<<<'PHP'
$_ENV['API_KEYS'] = 'mobile:private-mobile-key,admin:private-admin-key';
foreach (['private-mobile-key', 'private-admin-key'] as $key) {
    foreach ([false, true] as $fail) {
        $request = new App\Http\Request('GET', '/users', headers: ['X-Api-Key' => $key]);
        App\Http\Pipeline::run([new App\Middleware\ErrorHandler(), new App\Middleware\RequestId(), new App\Middleware\AccessLog(), new App\Middleware\ApiKeyAuth()], $request,
            static fn(): App\Http\Response => $fail ? throw new App\Http\HttpException(404, 'not_found', 'Not found') : App\Http\Response::json(['data' => []]));
    }
}
PHP);
        $access = array_values(array_filter($records, static fn(array $row): bool => isset($row['method'])));
        self::assertCount(4, $access);
        self::assertSame(['mobile', 'mobile', 'admin', 'admin'], array_column($access, 'client'));
        self::assertSame([200, 404, 200, 404], array_column($access, 'status'));
        self::assertSame('', $output);
        self::assertStringNotContainsString('private-mobile-key', json_encode($records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private-admin-key', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function test_require_json_only_restricts_post_put_and_patch(): void
    {
        foreach (['POST', 'PUT', 'PATCH'] as $method) {
            foreach (['', 'text/plain', 'application/jsonp', 'application/problem+json'] as $type) {
                $response = (new RequireJson())->handle(new Request($method, '/users', headers: ['Content-Type' => $type]), static fn(): never => throw new \LogicException('Core must not run'));
                self::assertSame(415, $response->status);
            }
            $response = (new RequireJson())->handle(new Request($method, '/users', headers: ['Content-Type' => 'Application/JSON; charset=utf-8']), static fn(): Response => Response::noContent());
            self::assertSame(204, $response->status);
        }
        foreach (['GET', 'HEAD', 'DELETE', 'OPTIONS'] as $method) {
            self::assertSame(204, (new RequireJson())->handle(new Request($method, '/users'), static fn(): Response => Response::noContent())->status);
        }
    }

    public function test_authentication_precedes_json_requirements(): void
    {
        $layers = [new ApiKeyAuth(), new RequireJson()];
        $core = static fn(): Response => Response::noContent();
        self::assertSame(401, Pipeline::run($layers, new Request('POST', '/users'), $core)->status);
        self::assertSame(415, Pipeline::run($layers, new Request('POST', '/users', headers: ['X-Api-Key' => 'test-key']), $core)->status);
        self::assertSame(204, Pipeline::run($layers, new Request('POST', '/users', headers: ['X-Api-Key' => 'test-key', 'Content-Type' => 'application/json']), $core)->status);
    }

    public function test_error_logs_and_debug_details_redact_secrets_and_omit_trace_arguments(): void
    {
        [$output, $records] = $this->captureStderr(<<<'PHP'
ini_set('zend.exception_ignore_args', '0');
$_ENV['DB_HOST'] = 'private-host.example';
$_ENV['DB_NAME'] = 'private_database';
$_ENV['DB_USER'] = 'private_database_user';
$_ENV['DB_PASSWORD'] = 'private-database-password';
$_ENV['API_KEYS'] = 'test:private-api-key';
$request = new App\Http\Request('POST', '/users', headers: ['X-Api-Key' => 'private-api-key'], body: ['password' => 'private-body-password']);
$results = [];
foreach (['0', '1'] as $debug) {
    $_ENV['APP_DEBUG'] = $debug;
    $core = static function (string $argument): never {
        throw new RuntimeException('Failed private-host.example private_database private_database_user private-database-password private-api-key private-body-password '.'private-api-key');
    };
    $response = App\Http\Pipeline::run([new App\Middleware\ErrorHandler(), new App\Middleware\RequestId()], $request,
        static fn(): never => $core('private-trace-argument'));
    $results[] = json_decode($response->body, true);
}
echo json_encode($results, JSON_THROW_ON_ERROR);
PHP);
        foreach (['private-host.example', 'private_database', 'private_database_user', 'private-database-password',
            'private-api-key', 'private-body-password', 'private-trace-argument', 'private-api-key'] as $secret) {
            self::assertStringNotContainsString($secret, $output . json_encode($records, JSON_THROW_ON_ERROR));
        }
        $results = json_decode($output, true);
        self::assertSame([], $results[0]['error']['details']);
        self::assertIsArray($results[1]['error']['details']['trace']);
        self::assertCount(2, $records);
        foreach ($records as $index => $record) {
            self::assertSame($results[$index]['error']['request_id'], $record['context']['request_id']);
            self::assertSame('RuntimeException', $record['context']['class']);
            self::assertStringContainsString('DB_PASSWORD', $record['context']['message']);
            self::assertSame($index === 1, isset($record['context']['trace']));
            foreach ($record['context']['trace'] ?? [] as $frame) {
                self::assertArrayNotHasKey('args', $frame);
                self::assertArrayNotHasKey('object', $frame);
            }
        }
    }

    public function test_returned_errors_also_receive_a_request_id_in_the_body(): void
    {
        $response = Pipeline::run(
            [new RequestId(), new ApiKeyAuth()],
            new Request('GET', '/users'),
            static fn(): never => throw new \LogicException('Core must not run'),
        );
        self::assertSame(401, $response->status);
        self::assertSame($response->headers['X-Request-Id'], json_decode($response->body, true)['error']['request_id']);
    }

    public function test_logger_redacts_named_context_secrets_and_bearer_credentials(): void
    {
        [$output, $records] = $this->captureStderr(<<<'PHP'
App\Log\Logger::error('password=unconfigured-password Authorization: Bearer unconfigured-key', [
    'password' => 'unconfigured-context-password', 'nested' => ['token' => 'unconfigured-token', 'passwd' => 123456789],
    'message' => '{"password":"unconfigured-json-password"}', 'object' => (object) ['password' => 'unconfigured-object-password'],
]);
PHP);
        foreach (['unconfigured-password', 'unconfigured-key', 'unconfigured-context-password', 'unconfigured-token', '123456789', 'unconfigured-json-password', 'unconfigured-object-password'] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($records, JSON_THROW_ON_ERROR));
        }
        self::assertSame('', $output);
        self::assertSame('error', $records[0]['level']);
    }

    /** @return array{string, list<array<string, mixed>>} */
    private function captureStderr(string $code): array
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $process = proc_open([PHP_BINARY, '-r', "require {$autoload};\n" . $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        self::assertIsString($output);
        self::assertIsString($stderr);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        $records = $stderr === '' ? [] : array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", rtrim($stderr, "\n")));

        return [$output, $records];
    }
}
