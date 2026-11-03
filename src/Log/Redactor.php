<?php
declare(strict_types=1);

namespace App\Log;

use App\Http\Request;
use App\Config;

final class Redactor
{
    /** @return ($data is array ? array<array-key, mixed> : ($data is string ? string : mixed)) */
    public static function clean(mixed $data, ?Request $request = null, bool $context = false): mixed
    {
        $values = [];
        $remember = static function (string $name, mixed $value) use (&$values): void {
            if (is_scalar($value) && (string) $value !== '') {
                $values[(string) $value] = '[' . $name . ']';
            }
        };
        foreach (Config::all() as $name => $value) {
            if (preg_match('/\ADB_|PASSWORD|PASSWD|SECRET|TOKEN|API_KEY/i', $name)) {
                $remember($name, $value);
                if ($name === 'API_KEYS') {
                    foreach (explode(',', (string) $value) as $entry) {
                        $remember($name, trim($entry));
                        $key = explode(':', trim($entry), 2)[1] ?? '';
                        $remember($name, $key);
                    }
                }
            }
        }
        $headers = array_merge([
            'x-api-key' => $_SERVER['HTTP_X_API_KEY'] ?? '',
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        ], $request === null ? [] : $request->headers);
        foreach (['x-api-key', 'authorization'] as $name) {
            $remember($name, $headers[$name] ?? '');
        }
        if (preg_match('/\ABearer\s+(\S+)\z/i', $headers['authorization'], $match)) {
            $remember('authorization', $match[1]);
        }
        /** @param array<array-key, mixed> $input */
        $collect = static function (array $input, bool $sensitive = false) use (&$collect, $remember): void {
            foreach ($input as $name => $value) {
                $secret = $sensitive || preg_match('/password|passwd|secret|token|api[_-]?key|authorization|\ADB_/i', (string) $name);
                if (is_array($value)) {
                    $collect($value, (bool) $secret);
                } elseif ($secret) {
                    $remember('input', $value);
                }
            }
        };
        $collect(array_merge($_GET, $_POST, $request === null ? [] : $request->query, $request === null ? [] : $request->body));
        if ($context && is_array($data)) { $collect($data); }
        uksort($values, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $clean = static function (mixed $value) use (&$clean, $values, $context): mixed {
            if (is_array($value)) {
                $result = [];
                foreach ($value as $name => $item) {
                    $result[$name] = $context && preg_match('/password|passwd|secret|token|api[_-]?key|authorization|\ADB_/i', (string) $name)
                        ? '[' . $name . ']' : $clean($item);
                }
                return $result;
            }
            if (!is_string($value)) {
                return is_object($value) ? $value::class : (is_resource($value) ? get_resource_type($value) : $value);
            }
            foreach ($values as $secret => $name) {
                $value = strlen((string) $secret) < 4 || (str_starts_with($name, '[DB_') && !str_contains($name, 'PASSWORD'))
                    ? (preg_replace('/(?<![A-Za-z0-9_])' . preg_quote((string) $secret, '/') . '(?![A-Za-z0-9_])/', $name, $value) ?? '[redacted]')
                    : str_replace((string) $secret, $name, $value);
            }
            $value = preg_replace('/\b(password|passwd|secret|token|api[_-]?key|DB_[A-Z0-9_]+)["\']?\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '$1=[redacted]', $value) ?? '[redacted]';
            $value = preg_replace('/\bBearer\s+\S+/i', 'Bearer [authorization]', $value) ?? '[redacted]';
            return mb_scrub($value, 'UTF-8');
        };
        return $clean($data);
    }
}
