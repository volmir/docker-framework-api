<?php
declare(strict_types=1);

namespace App\Console;

use App\Http\Middleware;
use PhpToken;

/** @return list<string> Literal keys in the returned route array, before PHP overwrites duplicates. */
function routeKeys(string $source): array
{
    $tokens = array_values(array_filter(PhpToken::tokenize($source),
        static fn(PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
    $keys = [];
    $depth = 0;
    foreach ($tokens as $index => $token) {
        if ($token->text === '[') { ++$depth; }
        if ($token->text === ']') { --$depth; }
        if ($depth === 1 && $token->is(T_CONSTANT_ENCAPSED_STRING) && ($tokens[$index + 1] ?? null)?->is(T_DOUBLE_ARROW)) {
            $value = substr($token->text, 1, -1);
            $keys[] = $token->text[0] === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value) : stripcslashes($value);
        }
    }
    return $keys;
}

/**
 * @param array<string, array{0:string, 1:string, 2?:list<string>}> $routes
 * @param list<string> $global
 * @param list<string>|null $keys Original keys, including duplicates lost when loading the PHP array.
 * @return array{output:string, errors:list<string>}
 */
function routesTable(array $routes, array $global, bool $check = false, ?array $keys = null): array
{
    $short = static fn(string $class): string => substr($class, (int) strrpos('\\' . $class, '\\'));
    $middlewareErrors = static function (array $classes, string $scope): array {
        $errors = [];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                $errors[] = "{$scope}: middleware class does not exist: {$class}";
            } elseif (!is_subclass_of($class, Middleware::class)) {
                $errors[] = "{$scope}: middleware must implement " . Middleware::class . ": {$class}";
            }
        }
        return $errors;
    };
    $errors = $globalErrors = $check ? $middlewareErrors($global, 'Global') : [];
    $counts = array_count_values($keys ?? array_keys($routes));
    $rows = [['METHOD', 'PATH', 'HANDLER', 'MIDDLEWARE']];
    foreach ($routes as $key => [$class, $method]) {
        $layers = $routes[$key][2] ?? [];
        $problems = [];
        if ($check) {
            if (!class_exists($class)) {
                $problems[] = "{$key}: controller class does not exist: {$class}";
            } elseif (!method_exists($class, $method)) {
                $problems[] = "{$key}: controller method does not exist: {$class}@{$method}";
            }
            $problems = array_merge($problems, $middlewareErrors($layers, $key));
            if (($counts[$key] ?? 0) > 1) { $problems[] = "{$key}: duplicate route key"; }
        }
        [$verb, $path] = explode(' ', $key, 2);
        $rows[] = [($problems !== [] || $globalErrors !== [] ? '! ' : '') . $verb, $path,
            $short($class) . '@' . $method, $layers === [] ? '-' : implode(', ', array_map($short, $layers))];
        $errors = array_merge($errors, $problems);
    }
    $widths = [0, 0, 0, 0];
    foreach ($rows as $row) {
        foreach ($row as $column => $cell) { $widths[$column] = max($widths[$column], mb_strlen($cell, 'UTF-8')); }
    }
    $lines = ['Global: ' . implode(' → ', array_map($short, $global))];
    foreach ($rows as $row) {
        foreach ($row as $column => &$cell) { $cell .= str_repeat(' ', $widths[$column] - mb_strlen($cell, 'UTF-8')); }
        unset($cell);
        $lines[] = rtrim(implode(' | ', $row));
    }
    return ['output' => implode(PHP_EOL, $lines) . PHP_EOL, 'errors' => $errors];
}
