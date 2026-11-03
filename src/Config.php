<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Config
{
    public const array REQUIRED = [
        'APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
        'API_KEYS', 'CORS_ALLOW_ORIGIN',
    ];
    public const string API_CLIENT_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';

    public static function boot(): void
    {
        self::require(...self::REQUIRED);
        self::assertSafe();
    }

    /** @return list<array{0:string, 1:string}> */
    public static function apiKeys(): array
    {
        $keys = [];
        foreach (explode(',', self::get('API_KEYS', '')) as $entry) {
            [$client, $key] = array_pad(explode(':', trim($entry), 2), 2, '');
            if (!preg_match(self::API_CLIENT_PATTERN, $client) || !preg_match('/\A[A-Za-z0-9_-]+\z/', $key)) {
                throw new RuntimeException('Invalid environment variable: API_KEYS');
            }
            $keys[] = [$client, $key];
        }
        return $keys;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return array_merge(getenv(), $_ENV);
    }

    public static function env(): string
    {
        $environment = self::get('APP_ENV', 'production');
        if (!in_array($environment, ['local', 'test', 'production'], true)) {
            throw new RuntimeException('Invalid environment variable: APP_ENV');
        }
        return $environment;
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }

    public static function isDebug(): bool
    {
        return !self::isProduction() && self::bool('APP_DEBUG');
    }

    public static function assertSafe(): void
    {
        if (!self::isProduction()) {
            return;
        }
        $problems = [];
        try {
            if (self::bool('APP_DEBUG')) {
                $problems[] = 'APP_DEBUG';
            }
        } catch (RuntimeException) {
            $problems[] = 'APP_DEBUG';
        }
        try {
            self::apiKeys();
        } catch (RuntimeException) {
            $problems[] = 'API_KEYS';
        }
        foreach (['DB_PASSWORD', 'DB_ROOT_PASSWORD'] as $key) {
            if (in_array(self::get($key, ''), ['', 'change-me', 'change-me-too'], true)) {
                $problems[] = $key;
            }
        }
        if (trim(self::get('CORS_ALLOW_ORIGIN', '*')) === '*') {
            $problems[] = 'CORS_ALLOW_ORIGIN';
        }
        if ($problems !== []) {
            throw new RuntimeException('Unsafe production configuration: ' . implode(', ', $problems));
        }
    }

    public static function get(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false) {
            return $default ?? throw new RuntimeException("Missing environment variable: {$key}");
        }

        return (string) $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = filter_var(self::get($key, (string) $default), FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new RuntimeException("Environment variable {$key} must be an integer");
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = filter_var(self::get($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new RuntimeException("Environment variable {$key} must be a boolean");
        }

        return $value;
    }

    public static function require(string ...$keys): void
    {
        $missing = array_filter($keys, static fn(string $key): bool => !array_key_exists($key, $_ENV) && getenv($key) === false);
        if ($missing !== []) {
            throw new RuntimeException('Missing environment variables: ' . implode(', ', $missing));
        }
    }
}
