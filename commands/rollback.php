<?php
declare(strict_types=1);

return [
    'name' => 'rollback',
    'description' => 'Rollback migration batches',
    'execute' => function (array $args): int {
        /** @var PDO $pdo */
        $pdo = require __DIR__ . '/../src/bootstrap.php';
        $steps = (int) ($args[0] ?? 1);
        $migrator = new App\Database\Migrator($pdo, __DIR__ . '/../database/migrations');
        $rolledBack = $migrator->rollback($steps);
        if ($rolledBack === []) {
            echo "Nothing to roll back.\n";
            return 0;
        }
        foreach ($rolledBack as $name) {
            echo "\033[33mRolled back: {$name}\033[0m\n";
        }
        return 0;
    },
];
