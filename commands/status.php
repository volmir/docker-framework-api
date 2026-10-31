<?php
declare(strict_types=1);

return [
    'name' => 'status',
    'description' => 'Show migration status',
    'execute' => function (array $args): int {
        /** @var PDO $pdo */
        $pdo = require __DIR__ . '/../src/bootstrap.php';
        $migrator = new App\Database\Migrator($pdo, __DIR__ . '/../database/migrations');
        foreach ($migrator->status() as $migration) {
            echo $migration['name'] . ' — ' . $migration['status'] . "\n";
        }
        return 0;
    },
];
