<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StartupTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function debugModes(): iterable
    {
        yield 'local without debug' => ['local', '0', false];
        yield 'local with debug' => ['local', '1', true];
        yield 'production hides debug' => ['production', '1', false];
    }

    #[DataProvider('debugModes')]
    public function test_http_boot_failure_logs_names_and_precedes_request_parsing(string $mode, string $debug, bool $visible): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $log = tempnam(sys_get_temp_dir(), 'startup-http-');
        self::assertIsString($log);
        $environment = array_replace(Config::all(), [
            'APP_ENV' => $mode, 'APP_DEBUG' => $debug,
            'DB_PORT' => '1', 'DB_NAME' => 'startup-private-database', 'DB_USER' => 'startup-private-user',
            'DB_PASSWORD' => 'startup-private-password', 'DB_ROOT_PASSWORD' => 'startup-private-root',
            'API_KEYS' => 'startup:startup-key',
            'CORS_ALLOW_ORIGIN' => 'https://client.example',
        ]);
        unset($environment['DB_HOST']);
        $process = proc_open(
            [PHP_BINARY, '-S', $address, dirname(__DIR__, 2) . '/public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            env_vars: $environment,
        );
        self::assertIsResource($process);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $probe = @stream_socket_client('tcp://' . $address, timeout: 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(10000);
            }
            self::assertTrue($ready, 'PHP HTTP server did not start');
            $body = file_get_contents('http://' . $address . '/users', false, stream_context_create(['http' => [
                'method' => 'POST', 'header' => 'Content-Type: application/json',
                'content' => '{invalid-json', 'ignore_errors' => true, 'timeout' => 5,
            ]]));
            self::assertIsString($body);
            $headers = http_get_last_response_headers() ?? [];
            self::assertStringContainsString('500', $headers[0]);
            self::assertStringContainsString('Content-Type: application/json', implode("\n", $headers));
            $error = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['error'];
            self::assertSame('internal_error', $error['code']);
            if ($visible) {
                self::assertSame('Missing environment variables: DB_HOST', $error['details']['message']);
            } else {
                self::assertSame([], $error['details']);
                self::assertStringNotContainsString('DB_HOST', $body);
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
            $stderr = file_get_contents($log);
            unlink($log);
        }
        self::assertIsString($stderr);
        self::assertStringContainsString('Missing environment variables: DB_HOST', $stderr);
        foreach (['startup-private-database', 'startup-private-user', 'startup-private-password', 'startup-private-root'] as $secret) {
            self::assertStringNotContainsString($secret, $stderr . $body);
        }
    }
}
