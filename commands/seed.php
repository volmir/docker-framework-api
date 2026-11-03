<?php

declare(strict_types=1);

use App\Console\Console;
use App\Config;
use App\Database\Connection;

/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'seed',
    'description' => 'Run seeders (all or one by name)',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        if (Config::isProduction() && !in_array('--force', $args, true)) {
            throw new RuntimeException('Seeding in production requires --force');
        }
        $args = array_values(array_filter($args, static fn(string $arg): bool => $arg !== '--force'));
        if (count($args) > 1) {
            throw new RuntimeException('Usage: seed [name] [--force]');
        }
        $seeder = new App\Database\Seeder($connection->pdo(), __DIR__ . '/../database/seeds');
        $name = $args[0] ?? null;
        $ran = $seeder->run($name);
        foreach ($ran as $seedName) {
            echo Console::color("Seeded: {$seedName}", 32) . PHP_EOL;
        }
        return 0;
    },
];

return $command;
