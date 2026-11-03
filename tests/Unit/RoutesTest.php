<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Console;
use App\Controller\HealthController;
use App\Http\Middleware;
use App\Middleware\ApiKeyAuth;
use App\Middleware\ErrorHandler;
use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase
{
    public function test_table_collects_all_configuration_errors_and_marks_only_problem_rows(): void
    {
        $routes = [
            'GET /missing' => ['App\\Controller\\MissingController', 'index'],
            'GET /method' => [HealthController::class, 'missingMethod'],
            'GET /middleware' => [HealthController::class, 'index', ['App\\Middleware\\MissingMiddleware', HealthController::class]],
            'GET /duplicate' => [HealthController::class, 'index'],
            'GET /valid' => [HealthController::class, 'index', [ApiKeyAuth::class]],
        ];
        $result = Console::routesTable($routes, [ErrorHandler::class], true, [...array_keys($routes), 'GET /duplicate']);

        self::assertSame([
            'GET /missing: controller class does not exist: App\\Controller\\MissingController',
            'GET /method: controller method does not exist: ' . HealthController::class . '@missingMethod',
            'GET /middleware: middleware class does not exist: App\\Middleware\\MissingMiddleware',
            'GET /middleware: middleware must implement ' . Middleware::class . ': ' . HealthController::class,
            'GET /duplicate: duplicate route key',
        ], $result['errors']);
        self::assertSame(4, substr_count($result['output'], '! GET'));
        self::assertMatchesRegularExpression('/^GET +\| \/valid +\|/m', $result['output']);
    }

    public function test_invalid_global_middleware_are_reported_and_mark_every_route(): void
    {
        $result = Console::routesTable(
            ['GET /health' => [HealthController::class, 'index']],
            ['MissingGlobal', HealthController::class],
            true,
        );

        self::assertSame([
            'Global: middleware class does not exist: MissingGlobal',
            'Global: middleware must implement ' . Middleware::class . ': ' . HealthController::class,
        ], $result['errors']);
        self::assertStringContainsString('! GET', $result['output']);
    }

    public function test_listing_does_not_validate_classes_without_check(): void
    {
        $result = Console::routesTable(['GET /missing' => ['MissingController', 'index', ['MissingMiddleware']]], ['MissingGlobal']);

        self::assertSame([], $result['errors']);
        self::assertStringContainsString('MissingController@index', $result['output']);
        self::assertStringNotContainsString('!', $result['output']);
    }

    public function test_utf8_columns_follow_the_longest_cell_and_preserve_route_order(): void
    {
        $result = Console::routesTable([
            'GET /пользователи' => ['Пример\\Контроллер', 'список'],
            'POST /a' => [HealthController::class, 'index', [ApiKeyAuth::class, ErrorHandler::class]],
        ], [ErrorHandler::class]);
        $lines = explode(PHP_EOL, trim($result['output']));
        self::assertSame('Global: ErrorHandler', array_shift($lines));
        $header = explode(' | ', $lines[0]);
        foreach ($lines as $line) {
            $cells = explode(' | ', $line);
            foreach ([0, 1, 2] as $column) {
                self::assertSame(mb_strlen($header[$column], 'UTF-8'), mb_strlen($cells[$column], 'UTF-8'));
            }
        }
        self::assertSame(mb_strlen('/пользователи', 'UTF-8'), mb_strlen($header[1], 'UTF-8'));
        self::assertStringContainsString('/пользователи', $lines[1]);
        self::assertStringContainsString('Контроллер@список', $lines[1]);
        self::assertStringEndsWith(' | -', $lines[1]);
        self::assertStringEndsWith('ApiKeyAuth, ErrorHandler', $lines[2]);
    }

    public function test_source_keys_preserve_duplicates_and_ignore_comments_and_nested_arrays(): void
    {
        $source = <<<'PHP'
<?php
// 'GET /comment' => []
return [
    'GET /same' /* comment */ => [Example::class, 'index', ['GET /nested' => Other::class]],
    "GET /same" => [Example::class, 'index'],
    'GET /other' => [Example::class, 'index'],
    "GET /\x6fther" => [Example::class, 'index'],
];
PHP;
        self::assertSame(['GET /same', 'GET /same', 'GET /other', 'GET /other'], Console::routeKeys($source));
        self::assertSame([], Console::routeKeys('<?php return [];'));
    }
}
