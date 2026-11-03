<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Config;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    private const array SAFE = [
        'APP_ENV' => 'production', 'APP_DEBUG' => '0',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
        'DB_NAME' => 'app', 'DB_USER' => 'app',
        'DB_PASSWORD' => 'private-app-password', 'DB_ROOT_PASSWORD' => 'private-root-password',
        'CORS_ALLOW_ORIGIN' => 'https://client.example',
    ];

    public function test_production_defaults_stop_every_cli_entry_before_execution(): void
    {
        $settings = ['APP_ENV' => 'production', 'APP_DEBUG' => '1', 'API_KEYS' => '',
            'DB_PASSWORD' => 'change-me', 'DB_ROOT_PASSWORD' => 'change-me-too', 'CORS_ALLOW_ORIGIN' => '*'];
        foreach (['env:check', 'apikey:generate'] as $command) {
            [$status, $stdout, $stderr] = $this->console($command, $settings);
            self::assertSame(1, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString('Unsafe production configuration: APP_DEBUG, API_KEYS, DB_PASSWORD, DB_ROOT_PASSWORD, CORS_ALLOW_ORIGIN', $stderr);
            self::assertStringNotContainsString('change-me', $stderr);
            self::assertStringNotContainsString('"trace"', $stderr);
            self::assertStringNotContainsString('Error formatter unavailable', $stderr);
        }
    }

    public function test_safe_env_check_works_without_a_database(): void
    {
        [$status, $stdout, $stderr] = $this->console('env:check', self::SAFE);
        self::assertSame(0, $status, $stderr);
        self::assertSame("Environment production is safe.\n", $stdout);
        self::assertSame('', $stderr);
    }

    public function test_help_skips_boot_even_with_missing_or_unsafe_environment(): void
    {
        $settings = array_fill_keys(Config::REQUIRED, null);
        $settings['APP_DEBUG'] = '1';
        foreach (['', 'list', 'help', '--help', '-h'] as $command) {
            [$status, $stdout, $stderr] = $this->console($command, $settings);
            self::assertSame(0, $status, $stderr);
            self::assertStringContainsString('Available commands:', $stdout);
            self::assertStringContainsString('env:check', $stdout);
            self::assertSame('', $stderr);
        }
    }

    public function test_cli_reports_missing_names_before_executing_any_command(): void
    {
        foreach (['env:check', 'migrate', 'apikey:generate'] as $command) {
            [$status, $stdout, $stderr] = $this->console($command, ['APP_ENV' => null, 'DB_HOST' => null, 'DB_USER' => null]);
            self::assertSame(1, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString('Missing environment variables: APP_ENV, DB_HOST, DB_USER', $stderr);
            self::assertStringNotContainsString('private-app-password', $stderr);
            self::assertStringNotContainsString('private-root-password', $stderr);
            self::assertStringNotContainsString('Connection refused', $stderr);
        }
    }

    public function test_env_check_command_itself_uses_boot(): void
    {
        $root = var_export(dirname(__DIR__, 2), true);
        [$status, $stdout, $stderr] = $this->process([PHP_BINARY, '-r', '$root = ' . $root . ';'
            . 'require $root . "/vendor/autoload.php";'
            . '$command = require $root . "/commands/env-check.php";'
            . 'exit($command["execute"]([], new App\\Database\\Connection()));'], ['DB_HOST' => null]);
        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertSame("Missing environment variables: DB_HOST\n", $stderr);
    }

    public function test_local_and_test_do_not_reject_example_credentials(): void
    {
        foreach (['local', 'test'] as $mode) {
            [$status, $stdout, $stderr] = $this->console('env:check', ['APP_ENV' => $mode, 'APP_DEBUG' => '1',
                'API_KEYS' => '', 'DB_PASSWORD' => 'change-me', 'DB_ROOT_PASSWORD' => 'change-me-too', 'CORS_ALLOW_ORIGIN' => '*']);
            self::assertSame(0, $status, $stderr);
            self::assertSame("Environment {$mode} is safe.\n", $stdout);
        }
    }

    public function test_invalid_environment_is_named_without_disclosing_its_value(): void
    {
        [$status, $stdout, $stderr] = $this->console('env:check', ['APP_ENV' => 'private-unknown-environment']);
        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Invalid environment variable: APP_ENV', $stderr);
        self::assertStringNotContainsString('private-unknown-environment', $stderr);
    }

    /** @param array<string, string|null> $settings
     * @return array{int, string, string} */
    private function console(string $command, array $settings): array
    {
        return $this->process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/console', ...($command === '' ? [] : [$command])], $settings);
    }

    /** @param list<string> $command
     * @param array<string, string|null> $settings
     * @return array{int, string, string} */
    private function process(array $command, array $settings): array
    {
        $environment = array_replace(Config::all(), self::SAFE, ['API_KEYS' => 'test:environment-test-key'], $settings);
        $environment = array_filter($environment, static fn(mixed $value): bool => $value !== null);
        // proc_open drops empty values from env_vars; env preserves NAME= for the child.
        $empty = array_map(static fn(string $key): string => $key . '=', array_keys(array_filter($environment, static fn(mixed $value): bool => $value === '')));
        $process = proc_open(
            ['env', ...$empty, ...$command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }
}
