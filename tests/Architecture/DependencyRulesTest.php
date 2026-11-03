<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PhpToken;
use PHPUnit\Framework\TestCase;

final class DependencyRulesTest extends TestCase
{
    public function test_domain_dependencies_and_controller_database_boundaries(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $domains = array_fill_keys(array_diff(array_map('basename', glob($root . '/*', GLOB_ONLYDIR) ?: []), ['Http', 'Middleware', 'Controller', 'Database', 'Console', 'Log']), true);
        $errors = $graph = $done = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = 'src/' . substr($file->getPathname(), strlen($root) + 1);
            $domain = explode('/', substr($relative, 4))[0];
            if (!isset($domains[$domain]) && $domain !== 'Controller') {
                continue;
            }
            $tokens = array_values(array_filter(PhpToken::tokenize(file_get_contents($file->getPathname()) ?: ''), static fn(PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
            $prefix = '';
            foreach ($tokens as $i => $token) {
                $name = ltrim($token->text, '\\');
                $location = $relative . ':' . $token->line;
                if ($domain === 'Controller' && (strcasecmp($name, 'PDO') === 0 || (in_array($token->text, ['->', '?->'], true) && in_array(strtolower($tokens[$i + 1]->text ?? ''), ['prepare', 'query'], true) && ($tokens[$i + 2]->text ?? '') === '('))) {
                    $errors[] = $location . ': direct PDO/prepare/query in controller';
                }
                if (in_array($token->text, [';', '}'], true)) {
                    $prefix = '';
                }
                if (!isset($domains[$domain]) || !$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || ($tokens[$i - 1]->id ?? null) === T_AS) {
                    continue;
                }
                if (($name === 'App' || str_starts_with($name, 'App\\')) && ($tokens[$i + 1]->text ?? '') === '\\' && ($tokens[$i + 2]->text ?? '') === '{') {
                    $prefix = $name . '\\';
                } elseif (!str_starts_with($name, 'App\\')) {
                    $name = $prefix . $name;
                }
                if (preg_match('/\AApp\\\\(?:Http|Controller|Middleware)(?:\\\\|\z)/i', $name)) {
                    $errors[] = $location . ': forbidden domain dependency ' . $name;
                }
                if (preg_match('/\AApp\\\\([^\\\\]+)(?:\\\\|\z)/', $name, $match) && isset($domains[$match[1]]) && $match[1] !== $domain) {
                    $graph[$domain][$match[1]] = $location;
                }
            }
        }
        $visit = static function (string $node, array $path) use (&$visit, &$done, $graph, &$errors): void {
            /** @var list<string> $path */
            if (($start = array_search($node, $path, true)) !== false) {
                $errors[] = $graph[$path[count($path) - 1]][$node] . ': domain cycle: ' . implode(' -> ', [...array_slice($path, $start), $node]);
                return;
            }
            if (isset($done[$node])) {
                return;
            }
            foreach (array_keys($graph[$node] ?? []) as $next) {
                $visit($next, [...$path, $node]);
            }
            $done[$node] = true;
        };
        foreach (array_keys($domains) as $domain) {
            $visit($domain, []);
        }
        self::assertSame([], array_values(array_unique($errors)), implode("\n", $errors));
    }

    public function test_helpers_have_a_specific_owner(): void
    {
        $root = dirname(__DIR__, 2);
        $errors = [];
        $hint = ': выберите место по docs/architecture.md';
        foreach (['src', 'bin', 'public', 'commands'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($directory === 'src' && preg_match('~(?:^|/)(?:[^/]*(?:Helpers?|Utils?|Manager)|Support|Common|Misc)(?:/|\.php$|$)~i', $relative)) {
                    $errors[] = $relative . ':1: generic helper name' . $hint;
                }
                if (!$file->isFile() || ($file->getExtension() !== 'php' && $directory !== 'bin')) {
                    continue;
                }
                $source = file_get_contents($file->getPathname()) ?: '';
                if ($directory === 'src') {
                    $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn(PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
                    foreach ($tokens as $i => $token) {
                        $name = $tokens[$i + 1] ?? null;
                        if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $name?->is(T_STRING) && preg_match('/(?:Helpers?|Utils?|Manager)\z/i', $name->text)) {
                            $errors[] = $relative . ':' . $token->line . ': generic class name ' . $name->text . $hint;
                        }
                    }
                }
                preg_match_all('/^function\s+\w+\(/m', $source, $functions, PREG_OFFSET_CAPTURE);
                foreach ($functions[0] as [$function, $offset]) {
                    $errors[] = $relative . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1) . ': named function outside a class' . $hint;
                }
            }
        }
        $source = file_get_contents($root . '/composer.json') ?: '';
        /** @var array{autoload?:array{files?:list<string>}} $composer */
        $composer = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        if (($composer['autoload']['files'] ?? []) !== []) {
            preg_match('/"files"\s*:/', $source, $match, PREG_OFFSET_CAPTURE);
            $errors[] = 'composer.json:' . (substr_count(substr($source, 0, $match[0][1] ?? 0), "\n") + 1) . ': nonempty autoload.files' . $hint;
        }
        self::assertSame([], $errors, implode("\n", $errors));
    }
}
