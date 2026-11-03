<?php
declare(strict_types=1);

use App\Database\Connection;

/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'status',
    'description' => 'Show migration status',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        $migrator = new App\Database\Migrator($connection->pdo(), __DIR__ . '/../database/migrations');
        foreach ($migrator->status() as $migration) {
            echo $migration['name'] . ' — ' . $migration['status'] . "\n";
        }
        return 0;
    },
];

return $command;
