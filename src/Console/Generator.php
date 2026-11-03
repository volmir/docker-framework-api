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
}
