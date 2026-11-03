<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    private const array KEYS = ['CONFIG_TEST_VALUE', 'CONFIG_TEST_MISSING', 'CONFIG_TEST_OTHER',
        ...Config::REQUIRED, 'APP_DEBUG', 'DB_ROOT_PASSWORD'];

    /** @var array<string, mixed> */
    private array $environment;
    /** @var array<string, string|false> */
    private array $processEnvironment;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        foreach (self::KEYS as $key) {
            $this->processEnvironment[$key] = getenv($key);
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
        foreach ($this->processEnvironment as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }

    public function test_env_takes_precedence_over_process_environment(): void
    {
        putenv('CONFIG_TEST_VALUE=process-value');
        self::assertSame('process-value', Config::get('CONFIG_TEST_VALUE'));

        $_ENV['CONFIG_TEST_VALUE'] = 'env-value';
        self::assertSame('env-value', Config::get('CONFIG_TEST_VALUE'));
    }

    public function test_defaults_only_apply_to_missing_variables(): void
    {
        self::assertSame('fallback', Config::get('CONFIG_TEST_VALUE', 'fallback'));
        self::assertSame(42, Config::int('CONFIG_TEST_VALUE', 42));
        self::assertTrue(Config::bool('CONFIG_TEST_VALUE', true));

        $_ENV['CONFIG_TEST_VALUE'] = '0';
        self::assertSame('0', Config::get('CONFIG_TEST_VALUE', 'fallback'));
        self::assertSame(0, Config::int('CONFIG_TEST_VALUE', 42));
        self::assertFalse(Config::bool('CONFIG_TEST_VALUE', true));

        $_ENV['CONFIG_TEST_VALUE'] = '';
        self::assertSame('', Config::get('CONFIG_TEST_VALUE', 'fallback'));
    }

    public function test_missing_variable_without_default_has_a_readable_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing environment variable: CONFIG_TEST_MISSING');

        Config::get('CONFIG_TEST_MISSING');
    }

    public function test_require_reports_all_missing_names_without_values(): void
    {
        $_ENV['CONFIG_TEST_VALUE'] = 'secret-value';
        $_ENV['CONFIG_TEST_OTHER'] = '';

        try {
            Config::require('CONFIG_TEST_VALUE', 'CONFIG_TEST_MISSING', 'CONFIG_TEST_OTHER');
            self::fail('Missing configuration was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Missing environment variables: CONFIG_TEST_MISSING', $error->getMessage());
        }

        $_ENV['CONFIG_TEST_MISSING'] = '0';
        $_ENV['CONFIG_TEST_OTHER'] = 'present';
        Config::require('CONFIG_TEST_VALUE', 'CONFIG_TEST_MISSING', 'CONFIG_TEST_OTHER');
        Config::require();
    }

    /** @return iterable<string, array{string}> */
    public static function requiredVariables(): iterable
    {
        foreach (Config::REQUIRED as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('requiredVariables')]
    public function test_boot_reports_each_missing_variable_without_other_values(string $missing): void
    {
        foreach (Config::REQUIRED as $key) {
            $_ENV[$key] = $key === 'APP_ENV' ? 'local' : 'private-value-for-' . $key;
        }
        unset($_ENV[$missing]);
        try {
            Config::boot();
            self::fail('Missing configuration was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Missing environment variables: ' . $missing, $error->getMessage());
            foreach ($_ENV as $key => $value) {
                if (in_array($key, Config::REQUIRED, true)) {
                    self::assertStringNotContainsString($value, $error->getMessage());
                }
            }
        }
    }

    public function test_boot_reports_all_missing_variables_before_safety_checks(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing environment variables: ' . implode(', ', Config::REQUIRED));
        Config::boot();
    }

    public function test_legacy_hashed_setting_does_not_replace_api_keys(): void
    {
        foreach (Config::REQUIRED as $name) {
            $_ENV[$name] = $name === 'APP_ENV' ? 'local' : '';
        }
        unset($_ENV['API_KEYS']);
        $_ENV['API_KEY_HASHES'] = 'mobile:' . str_repeat('a', 64);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing environment variables: API_KEYS');
        Config::boot();
    }

    public function test_boot_accepts_empty_values_in_local_from_both_environment_sources(): void
    {
        foreach (['env', 'process'] as $source) {
            foreach (Config::REQUIRED as $key) {
                $value = $key === 'APP_ENV' ? 'local' : '';
                if ($source === 'env') {
                    $_ENV[$key] = $value;
                } else {
                    unset($_ENV[$key]);
                    putenv($key . '=' . $value);
                }
            }
            Config::boot();
            self::assertSame('', Config::get('API_KEYS'));
        }
    }

    public function test_integer_values_are_parsed(): void
    {
        foreach (['0' => 0, '42' => 42, '-3' => -3] as $value => $expected) {
            $_ENV['CONFIG_TEST_VALUE'] = (string) $value;
            self::assertSame($expected, Config::int('CONFIG_TEST_VALUE'));
        }
    }

    public function test_invalid_integer_error_does_not_expose_the_value(): void
    {
        $_ENV['CONFIG_TEST_VALUE'] = 'secret-value';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Environment variable CONFIG_TEST_VALUE must be an integer');

        Config::int('CONFIG_TEST_VALUE');
    }

    public function test_boolean_strings_are_parsed_without_truthy_string_casts(): void
    {
        foreach (['1' => true, 'true' => true, 'yes' => true, 'on' => true, '0' => false, 'false' => false, 'no' => false, 'off' => false] as $value => $expected) {
            $_ENV['CONFIG_TEST_VALUE'] = (string) $value;
            self::assertSame($expected, Config::bool('CONFIG_TEST_VALUE'));
        }
    }

    public function test_invalid_boolean_error_does_not_expose_the_value(): void
    {
        $_ENV['CONFIG_TEST_VALUE'] = 'secret-value';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Environment variable CONFIG_TEST_VALUE must be a boolean');

        Config::bool('CONFIG_TEST_VALUE');
    }

    public function test_environment_defaults_to_production_and_accepts_only_known_modes(): void
    {
        self::assertSame('production', Config::env());
        self::assertTrue(Config::isProduction());
        foreach (['local', 'test', 'production'] as $mode) {
            $_ENV['APP_ENV'] = $mode;
            self::assertSame($mode, Config::env());
            self::assertSame($mode === 'production', Config::isProduction());
        }
        foreach (['', 'staging', 'PRODUCTION', 'private-environment'] as $invalid) {
            $_ENV['APP_ENV'] = $invalid;
            try {
                Config::env();
                self::fail('Expected invalid environment');
            } catch (RuntimeException $error) {
                self::assertSame('Invalid environment variable: APP_ENV', $error->getMessage());
                self::assertStringNotContainsString('private-environment', $error->getMessage());
            }
        }
    }

    public function test_debug_is_disabled_in_production_even_for_true_or_invalid_values(): void
    {
        foreach (['production', 'local', 'test'] as $mode) {
            $_ENV['APP_ENV'] = $mode;
            foreach (['0' => false, '1' => true, 'true' => true, 'false' => false] as $value => $expected) {
                $_ENV['APP_DEBUG'] = (string) $value;
                self::assertSame($mode === 'production' ? false : $expected, Config::isDebug());
            }
        }
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'private-invalid-debug';
        self::assertFalse(Config::isDebug());
    }

    public function test_production_safety_checks_each_unsafe_setting_and_reports_names_only(): void
    {
        $safe = ['APP_ENV' => 'production', 'APP_DEBUG' => '0', 'API_KEYS' => 'test:config-test-key',
            'DB_HOST' => 'db', 'DB_PORT' => '3306', 'DB_NAME' => 'app', 'DB_USER' => 'app',
            'DB_PASSWORD' => 'private-app-password', 'DB_ROOT_PASSWORD' => 'private-root-password',
            'CORS_ALLOW_ORIGIN' => 'https://client.example'];
        foreach ([['APP_DEBUG', '1'], ['APP_DEBUG', 'true'], ['APP_DEBUG', 'invalid-debug'],
            ['API_KEYS', ''], ['API_KEYS', ', ,'], ['API_KEYS', 'legacy-key'], ['API_KEYS', 'mobile:invalid key'], ['DB_PASSWORD', 'change-me'],
            ['DB_ROOT_PASSWORD', 'change-me-too'], ['DB_PASSWORD', 'change-me-too'],
            ['DB_ROOT_PASSWORD', 'change-me'], ['DB_PASSWORD', ''], ['DB_ROOT_PASSWORD', ''],
            ['CORS_ALLOW_ORIGIN', '*']] as [$name, $value]) {
            $_ENV = array_replace($_ENV, $safe, [$name => $value]);
            try {
                Config::boot();
                self::fail('Expected unsafe configuration');
            } catch (RuntimeException $error) {
                self::assertSame('Unsafe production configuration: ' . $name, $error->getMessage());
                self::assertStringNotContainsString('private-app-password', $error->getMessage());
                self::assertStringNotContainsString('private-root-password', $error->getMessage());
                self::assertStringNotContainsString('invalid-debug', $error->getMessage());
            }
        }
        $_ENV = array_replace($_ENV, $safe);
        Config::boot();
        self::assertTrue(Config::isProduction());
    }

    public function test_production_reports_all_problems_and_other_modes_skip_checks(): void
    {
        $_ENV = array_replace($_ENV, ['APP_ENV' => 'production', 'APP_DEBUG' => '1', 'API_KEYS' => '',
            'DB_PASSWORD' => 'change-me', 'DB_ROOT_PASSWORD' => 'change-me-too', 'CORS_ALLOW_ORIGIN' => '*']);
        try {
            Config::assertSafe();
            self::fail('Expected unsafe configuration');
        } catch (RuntimeException $error) {
            self::assertSame('Unsafe production configuration: APP_DEBUG, API_KEYS, DB_PASSWORD, DB_ROOT_PASSWORD, CORS_ALLOW_ORIGIN', $error->getMessage());
        }
        foreach (['local', 'test'] as $mode) {
            $_ENV['APP_ENV'] = $mode;
            Config::assertSafe();
            self::assertSame($mode, Config::env());
        }
    }

    public function test_api_key_entries_preserve_clients_rotation_order_and_key_case(): void
    {
        $mobile = 'mobile-old';
        $admin = 'admin-key';
        $rotated = 'mobile-new';
        $_ENV['API_KEYS'] = ' mobile:' . $mobile . ', admin:' . strtoupper($admin) . ',mobile:' . $rotated . ' ';
        self::assertSame([['mobile', $mobile], ['admin', strtoupper($admin)], ['mobile', $rotated]], Config::apiKeys());
    }

    public function test_all_uses_the_same_precedence_as_get(): void
    {
        putenv('CONFIG_TEST_VALUE=process');
        $_ENV['CONFIG_TEST_VALUE'] = 'override';
        self::assertSame(Config::get('CONFIG_TEST_VALUE'), Config::all()['CONFIG_TEST_VALUE']);
    }

}
