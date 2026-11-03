<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase
{
    public function test_warnings_notices_and_deprecations_become_exceptions_and_respect_reporting(): void
    {
        [$code, $stdout, $stderr] = $this->execute(<<<'PHP'
error_reporting(E_ALL);
$result = [];
foreach ([E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED] as $level) {
    try { trigger_error('example', $level); }
    catch (ErrorException $error) { $result[] = $error->getSeverity(); }
}
error_reporting(E_ERROR);
trigger_error('ignored', E_USER_WARNING);
error_reporting(E_ALL);
@trigger_error('suppressed', E_USER_WARNING);
echo json_encode($result);
PHP);
        self::assertSame(0, $code, $stderr);
        self::assertSame([E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED], json_decode($stdout, true));
        self::assertSame('', $stderr);
    }

    public function test_uncaught_cli_exception_logs_safely_and_exits_one(): void
    {
        [$code, $stdout, $stderr] = $this->execute("throw new RuntimeException('CLI failure');");
        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('RuntimeException: CLI failure', $stderr);
        $record = json_decode(explode("\n", $stderr)[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('RuntimeException', $record['context']['class']);
        self::assertArrayHasKey('file', $record['context']);
        self::assertArrayNotHasKey('trace', $record['context']);
    }

    public function test_missing_autoload_is_handled_before_composer_without_raw_php_output(): void
    {
        [$code, $stdout, $stderr] = $this->execute("require '/tmp/framework-autoload-does-not-exist.php';");
        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('ErrorException:', $stderr);
        self::assertStringContainsString('Failed to open stream', $stderr);
        self::assertStringNotContainsString('PHP Warning:', $stderr);
    }

    public function test_fatal_compile_error_runs_shutdown_handler_and_exits_one(): void
    {
        [$code, $stdout, $stderr] = $this->execute(<<<'PHP'
$file = tempnam(sys_get_temp_dir(), 'fatal-');
file_put_contents($file, '<?php function duplicateFatalExample() {} function duplicateFatalExample() {}');
require $file;
PHP);
        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        $record = json_decode(explode("\n", $stderr)[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ErrorException', $record['context']['class']);
        self::assertStringContainsString('Cannot redeclare', $record['context']['message']);
        self::assertStringNotContainsString('PHP Fatal error:', $stderr);
    }

    public function test_warning_inside_pipeline_returns_500_with_request_id(): void
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        [$code, $stdout, $stderr] = $this->execute('require ' . $autoload . ';' . <<<'PHP'
$_ENV['APP_DEBUG'] = '1';
$response = App\Http\Pipeline::run([new App\Middleware\ErrorHandler(), new App\Middleware\RequestId()],
    new App\Http\Request('GET', '/', headers: ['X-Request-Id' => 'warning-test-123']),
    static function (): never { trigger_error('warning in controller', E_USER_WARNING); throw new LogicException('unreachable'); });
echo json_encode(['status' => $response->status, 'body' => json_decode($response->body, true)]);
PHP);
        self::assertSame(0, $code, $stderr);
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(500, $result['status']);
        self::assertSame('ErrorException', $result['body']['error']['details']['class']);
        self::assertSame('warning-test-123', $result['body']['error']['request_id']);
        self::assertIsArray($result['body']['error']['details']['trace']);
    }

    public function test_error_formatter_failure_still_logs_and_exits_one_without_secret_message(): void
    {
        $errors = var_export(dirname(__DIR__, 2) . '/src/Errors.php', true);
        $script = 'namespace App\\Middleware { final class ErrorHandler { public static function response(\\Throwable $error): never { throw new \\RuntimeException("formatter-secret-value"); } } } namespace { require ' . $errors . '; App\\Errors::register(); }';
        [$code, $stdout, $stderr] = $this->execute($script, false);
        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Error formatter unavailable', $stderr);
        self::assertStringNotContainsString('formatter-secret-value', $stderr);
        $record = json_decode(explode("\n", $stderr)[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('error', $record['level']);
        self::assertArrayHasKey('request_id', $record['context']);
    }

    /** @return array{int, string, string} */
    private function execute(string $script, bool $register = true): array
    {
        $errors = var_export(dirname(__DIR__, 2) . '/src/Errors.php', true);
        $file = tempnam(sys_get_temp_dir(), 'errors-script-');
        self::assertIsString($file);
        file_put_contents($file, '<?php ' . ($register ? 'require ' . $errors . '; App\Errors::register(); $_ENV["APP_DEBUG"] = "0"; ' : '') . $script);
        $process = proc_open(
            [PHP_BINARY, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        unlink($file);
        return [$status, $stdout, $stderr];
    }
}
