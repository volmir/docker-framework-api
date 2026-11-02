<?php
declare(strict_types=1);

use App\Database\Connection;

return [
    'name' => 'status',
    'description' => 'Show migration status',
    'execute' => static function (array $args, Connection $connection): int {
        $migrator = new App\Database\Migrator($connection->pdo(), __DIR__ . '/../database/migrations');
        foreach ($migrator->status() as $migration) {
            echo $migration['name'] . ' — ' . $migration['status'] . "\n";
        }
        return 0;
    },
];
