<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Console\Console;
use App\Database\Connection;
use App\Middleware\AccessLog;
use App\Middleware\Debug;
use App\Middleware\ErrorHandler;
use App\Middleware\RequestId;
use PHPUnit\Framework\TestCase;

final class RoutesCommandTest extends TestCase
{
    public function test_real_command_lists_current_routes_and_checks_them_without_database_access(): void
    {
        $environment = $_ENV;
        $_ENV['APP_ENV'] = 'test';
        $_ENV['APP_DEBUG'] = '0';
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_PORT'] = '1';
        try {
            $console = new Console(__DIR__ . '/../../commands', new Connection());
            foreach ([[], ['--check']] as $args) {
                ob_start();
                try {
                    self::assertSame(0, $console->run(['console', 'routes', ...$args]));
                    $output = ob_get_contents();
                    self::assertIsString($output);
                } finally {
                    ob_end_clean();
                }
                self::assertStringStartsWith("Global: ErrorHandler → RequestId → AccessLog\n", $output);
                self::assertMatchesRegularExpression('/^GET +\| \/health +\| HealthController@index +\| -$/m', $output);
                self::assertMatchesRegularExpression('/^GET +\| \/users +\| UserController@index +\| ApiKeyAuth$/m', $output);
                self::assertStringNotContainsString('!', $output);
            }
        } finally {
            $_ENV = $environment;
        }
    }

    public function test_middleware_file_preserves_order_and_only_enables_debug_outside_production(): void
    {
        $environment = $_ENV;
        try {
            foreach (['local', 'test', 'production'] as $env) {
                $_ENV['APP_ENV'] = $env;
                foreach (['0', '1'] as $debug) {
                    $_ENV['APP_DEBUG'] = $debug;
                    $expected = [ErrorHandler::class, RequestId::class, AccessLog::class];
                    if ($env !== 'production' && $debug === '1') { array_unshift($expected, Debug::class); }
                    self::assertSame($expected, require __DIR__ . '/../../src/middleware.php');
                }
            }
        } finally {
            $_ENV = $environment;
        }
    }

    public function test_command_returns_one_for_a_typo_and_a_duplicate_in_the_route_file(): void
    {
        $directory = sys_get_temp_dir() . '/routes-command-' . bin2hex(random_bytes(6));
        mkdir($directory . '/commands', 0777, true);
        mkdir($directory . '/src', 0777, true);
        copy(__DIR__ . '/../../commands/routes.php', $directory . '/commands/routes.php');
        file_put_contents($directory . '/src/middleware.php', '<?php return [App\\Middleware\\ErrorHandler::class];');
        file_put_contents($directory . '/src/routes.php', <<<'PHP'
<?php
return [
    'GET /typo' => ['App\Controller\TypoController', 'index'],
    'GET /duplicate' => [App\Controller\HealthController::class, 'index'],
    'GET /duplicate' => [App\Controller\HealthController::class, 'index'],
];
PHP);
        try {
            $command = require $directory . '/commands/routes.php';
            ob_start();
            try {
                $code = $command['execute'](['--check'], new Connection());
                $output = ob_get_contents();
                self::assertIsString($output);
            } finally {
                ob_end_clean();
            }
            self::assertSame(1, $code);
            self::assertSame(2, substr_count($output, '! GET'));
            self::assertStringContainsString('GET /typo: controller class does not exist: App\\Controller\\TypoController', $output);
            self::assertStringContainsString('GET /duplicate: duplicate route key', $output);
        } finally {
            foreach (['commands/routes.php', 'src/routes.php', 'src/middleware.php'] as $file) {
                unlink($directory . '/' . $file);
            }
            foreach (['src', 'commands', ''] as $path) { rmdir($directory . '/' . $path); }
        }
    }
}
