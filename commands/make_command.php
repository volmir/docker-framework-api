<?php
declare(strict_types=1);
use App\Config;
use App\Database\Connection;

return [
    'name' => 'make:command',
    'description' => 'Generate a console command in development',
    'execute' => static function (array $args, Connection $connection): int {
        $hint = 'Run make command name=report:users in a dev environment.';
        if (Config::isProduction()) { throw new RuntimeException('Generators are disabled in production. ' . $hint); }
        if (count($args) !== 1 || !preg_match('/\A[a-z][a-z0-9]*(?::[a-z][a-z0-9_]*)?\z/', $args[0])) { throw new RuntimeException('Usage: make:command <name>, e.g. report:users'); }
        $name = $args[0]; $path = __DIR__ . '/' . str_replace(':', '_', $name) . '.php';
        if (in_array($name, ['list', 'help'], true)) { throw new RuntimeException('Command already exists: ' . $name); }
        foreach (glob(__DIR__ . '/*.php') ?: [] as $file) { if ((require $file)['name'] === $name) { throw new RuntimeException('Command already exists: ' . $name); } }
        if (!is_writable(__DIR__)) { throw new RuntimeException('Command directory is not writable. ' . $hint); }
        $content = <<<'PHP'
<?php
declare(strict_types=1);

use App\Database\Connection;

return [
    'name' => '__NAME__',
    'description' => 'Describe what __NAME__ does',
    'execute' => static function (array $args, Connection $connection): int {
        if ($args !== []) { fwrite(STDERR, "Usage: __NAME__\n"); return 1; }
        // $pdo = $connection->pdo(); // Open the connection only when SQL is needed.
        return 0;
    },
];
PHP;
        $content = str_replace('__NAME__', $name, $content) . PHP_EOL;
        $handle = @fopen($path, 'x');
        if ($handle === false) { throw new RuntimeException('Cannot create command (exists or not writable). ' . $hint); }
        $written = @fwrite($handle, $content); $mode = @chmod($path, 0644); fclose($handle);
        if ($written !== strlen($content) || !$mode) { @unlink($path); throw new RuntimeException('Cannot write command. ' . $hint); }
        echo 'commands/' . basename($path) . PHP_EOL; return 0;
    },
];
