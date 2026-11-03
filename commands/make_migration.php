<?php

declare(strict_types=1);

use App\Console\Generator;
use App\Database\Connection;

/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'make:migration',
    'description' => 'Generate an empty migration in development',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        if (count($args) !== 1 || !preg_match('/\A[a-z][a-z0-9_]*\z/', $args[0])) {
            throw new RuntimeException('Usage: make:migration <name>, e.g. create_posts_table');
        }
        $name = $args[0];
        $dir = __DIR__ . '/../database/migrations';
        $files = glob($dir . '/*.php') ?: [];
        foreach ($files as $file) {
            if (substr(basename($file), 15) === $name . '.php') {
                throw new RuntimeException('Migration already exists: ' . $name);
            }
        }
        $stamp = gmdate('YmdHis');
        $latest = substr(basename(end($files) ?: ''), 0, 14);
        if ($stamp <= $latest) {
            $stamp = (DateTimeImmutable::createFromFormat('!YmdHis', $latest, new DateTimeZone('UTC')) ?: throw new RuntimeException('Invalid latest migration timestamp'))->modify('+1 second')->format('YmdHis');
        }
        $content = <<<'PHP'
<?php

declare(strict_types=1);

/** @var array{up:callable(PDO):void, down:callable(PDO):void} $migration */
$migration = [
    'up' => function (PDO $pdo): void {
        // $pdo->exec("CREATE TABLE ..."); // Write MySQL SQL here.
    },
    'down' => function (PDO $pdo): void {
        // $pdo->exec("DROP TABLE ..."); // Reverse the SQL from up.
    },
];
return $migration;
PHP;
        echo (new Generator(dirname(__DIR__)))->write('database/migrations/' . $stamp . '_' . $name . '.php', $content . PHP_EOL) . PHP_EOL;
        return 0;
    },
];
return $command;
