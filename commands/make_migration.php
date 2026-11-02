<?php
declare(strict_types=1);
use App\Config;
use App\Database\Connection;

return [
    'name' => 'make:migration',
    'description' => 'Generate an empty migration in development',
    'execute' => static function (array $args, Connection $connection): int {
        $hint = 'Run make migration name=create_posts_table in a dev environment.';
        if (Config::isProduction()) { throw new RuntimeException('Generators are disabled in production. ' . $hint); }
        if (count($args) !== 1 || !preg_match('/\A[a-z][a-z0-9_]*\z/', $args[0])) { throw new RuntimeException('Usage: make:migration <name>, e.g. create_posts_table'); }
        $name = $args[0]; $dir = __DIR__ . '/../database/migrations';
        if (!is_writable($dir)) { throw new RuntimeException('Migration directory is not writable. ' . $hint); }
        $files = glob($dir . '/*.php') ?: [];
        foreach ($files as $file) { if (substr(basename($file), 15) === $name . '.php') { throw new RuntimeException('Migration already exists: ' . $name); } }
        $stamp = gmdate('YmdHis'); $latest = substr(basename(end($files) ?: ''), 0, 14);
        if ($stamp <= $latest) { $stamp = (DateTimeImmutable::createFromFormat('!YmdHis', $latest, new DateTimeZone('UTC')) ?: throw new RuntimeException('Invalid latest migration timestamp'))->modify('+1 second')->format('YmdHis'); }
        $path = $dir . '/' . $stamp . '_' . $name . '.php';
        $content = <<<'PHP'
<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        // $pdo->exec("CREATE TABLE ..."); // Write MySQL SQL here.
    },
    'down' => function (PDO $pdo): void {
        // $pdo->exec("DROP TABLE ..."); // Reverse the SQL from up.
    },
];
PHP;
        $content .= PHP_EOL;
        $handle = @fopen($path, 'x');
        if ($handle === false) { throw new RuntimeException('Cannot create migration (exists or not writable). ' . $hint); }
        $written = @fwrite($handle, $content); $mode = @chmod($path, 0644); fclose($handle);
        if ($written !== strlen($content) || !$mode) { @unlink($path); throw new RuntimeException('Cannot write migration. ' . $hint); }
        echo 'database/migrations/' . basename($path) . PHP_EOL; return 0;
    },
];
