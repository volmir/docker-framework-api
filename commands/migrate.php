<?php
declare(strict_types=1);

return [
    'name' => 'migrate',
    'description' => 'Run pending migrations',
    'execute' => static function (array $args): int {
        /** @var PDO $pdo */
        $pdo = require __DIR__ . '/../src/bootstrap.php';
        $migrator = new App\Database\Migrator($pdo, __DIR__ . '/../database/migrations');
        $applied = $migrator->migrate();
        if ($applied === []) {
            echo "Nothing to migrate.\n";
            return 0;
        }
        foreach ($applied as $name) {
            echo "\033[32mMigrated: {$name}\033[0m\n";
        }
        return 0;
    },
];
