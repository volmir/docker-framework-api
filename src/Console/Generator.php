<?php
declare(strict_types=1);
namespace App\Console;
use App\Config;
use RuntimeException;

final class Generator
{
    public function __construct(private string $root) {}
    public function write(string $relativePath, string $content): string
    {
        $kind = match (explode('/', $relativePath)[0]) { 'commands' => 'command', 'database' => 'migration', default => match (explode('/', $relativePath)[1] ?? '') { 'Controller' => 'controller', 'Middleware' => 'middleware', default => 'domain' } };
        $hint = "Run make {$kind} name=... in a dev environment.";
        if (Config::isProduction()) { throw new RuntimeException('Generators are disabled in production. ' . $hint); }
        $path = $this->root . '/' . $relativePath; $dir = dirname($path);
        if (file_exists($path) || is_link($path)) { throw new RuntimeException("Cannot create {$kind} (already exists): {$relativePath}. " . $hint); }
        $mask = umask(0); $created = is_dir($dir) || @mkdir($dir, 0755, true); umask($mask);
        if (!$created || !is_writable($dir)) { throw new RuntimeException('Destination is not writable. ' . $hint); }
        $handle = @fopen($path, 'x');
        if ($handle === false) { throw new RuntimeException("Cannot create {$kind} (exists or not writable). " . $hint); }
        $written = @fwrite($handle, $content); $mode = @chmod($path, 0644); fclose($handle);
        if ($written !== strlen($content) || !$mode) { @unlink($path); throw new RuntimeException("Cannot write {$kind}. " . $hint); }
        return $relativePath;
    }
    public static function studly(string $name): string
    { return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name))); }
    public static function snake(string $name): string
    { return strtolower(preg_replace(['/([A-Z]+)([A-Z][a-z])/', '/([a-z0-9])([A-Z])/'], ['$1_$2', '$1_$2'], str_replace('-', '_', $name)) ?? $name); }
    /** @param array<string, string> $routes @param callable():void $create */
    public function withRoutes(array $routes, callable $create): void
    {
        if (Config::isProduction()) { throw new RuntimeException('Generators are disabled in production. Run make controller name=... in a dev environment.'); }
        $file = $this->root . '/src/routes.php'; $tmp = $backup = null; $installed = false;
        try {
            clearstatcache(true, $file); $source = @file_get_contents($file); $stat = @stat($file); if ($source === false || $stat === false || is_link($file)) { throw new RuntimeException('Cannot read regular src/routes.php'); }
            $lines = explode("\n", $source); $marker = '    // make:controller: new routes are inserted above this line'; $index = array_search($marker, $lines, true);
            if ($index === false || count(array_keys($lines, $marker, true)) !== 1) { throw new RuntimeException('Expected exactly one make:controller marker in src/routes.php'); }
            $load = static fn(): mixed => require $file; $existing = $load(); if (!is_array($existing)) { throw new RuntimeException('routes.php must return an array'); }
            foreach (array_intersect_key($routes, $existing) as $key => $_) { echo "Skipping existing route: {$key}\n"; unset($routes[$key]); }
            if ($routes !== []) {
                array_splice($lines, $index, 0, array_map(static fn(string $row): string => '    ' . $row, array_values($routes))); $updated = implode("\n", $lines);
                $tmp = @tempnam(dirname($file), '.routes-new-'); $backup = @tempnam(dirname($file), '.routes-backup-');
                if ($tmp === false || $backup === false || dirname($tmp) !== dirname($file) || dirname($backup) !== dirname($file) || !@copy($file, $backup) || @file_put_contents($tmp, $updated) !== strlen($updated)) { throw new RuntimeException('Cannot stage routes.php and its backup'); }
                foreach ([$tmp, $backup] as $path) { if (!@chown($path, $stat['uid']) || !@chgrp($path, $stat['gid']) || !@chmod($path, $stat['mode'] & 07777)) { throw new RuntimeException('Cannot preserve routes.php ownership and permissions'); } }
                if (!@rename($tmp, $file)) { throw new RuntimeException('Cannot replace routes.php'); } $installed = true;
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status); if ($status !== 0) { throw new RuntimeException('routes.php lint failed: ' . implode("\n", $output)); }
                $loaded = $load(); if (!is_array($loaded) || array_diff_key($routes, $loaded) !== []) { throw new RuntimeException('routes.php did not load inserted routes'); }
            }
            $create(); if ($routes !== []) { echo "Inserted " . count($routes) . " routes into src/routes.php\n"; }
        } catch (\Throwable $error) { if ($installed) { $restore = $backup; $backup = null; if (!is_string($restore) || !@rename($restore, $file)) { throw new RuntimeException('Cannot restore routes.php; backup: ' . (string) $restore, previous: $error); } } throw $error; }
        finally { foreach ([$tmp, $backup] as $path) { if (is_string($path) && is_file($path)) { @unlink($path); } } }
    }
}
