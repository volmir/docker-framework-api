<?php
declare(strict_types=1);

use App\Console\Console;
use App\Database\Connection;

/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'migrate',
    'description' => 'Run pending migrations',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
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

return $command;
