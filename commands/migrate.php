<?php
declare(strict_types=1);

use App\Console\Console;
use App\Database\Connection;

return [
    'name' => 'migrate',
    'description' => 'Run pending migrations',
    'execute' => static function (array $args, Connection $connection): int {
        $migrator = new App\Database\Migrator($connection->pdo(), __DIR__ . '/../database/migrations');
        $applied = $migrator->migrate();
        if ($applied === []) {
            echo "Nothing to migrate.\n";
            return 0;
        }
        foreach ($applied as $name) {
            echo Console::color("Migrated: {$name}", 32) . PHP_EOL;
        }
        return 0;
    },
];
