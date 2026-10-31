<?php
declare(strict_types=1);

return [
    'name' => 'seed',
    'description' => 'Run seeders (all or one by name)',
    'execute' => function (array $args): int {
        /** @var PDO $pdo */
        $pdo = require __DIR__ . '/../src/bootstrap.php';
        $seeder = new App\Database\Seeder($pdo, __DIR__ . '/../database/seeds');
        $name = $args[0] ?? null;
        $ran = $seeder->run($name);
        foreach ($ran as $seedName) {
            echo "\033[32mSeeded: {$seedName}\033[0m\n";
        }
        return 0;
    },
];
