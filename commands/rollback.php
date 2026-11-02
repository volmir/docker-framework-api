<?php
declare(strict_types=1);

use App\Console\Console;
use App\Config;
use App\Database\Connection;

return [
    'name' => 'rollback',
    'description' => 'Rollback migration batches',
    'execute' => static function (array $args, Connection $connection): int {
        if (Config::isProduction() && !in_array('--force', $args, true)) {
            throw new RuntimeException('Rollback in production requires --force');
        }
        $args = array_values(array_filter($args, static fn(string $arg): bool => $arg !== '--force'));
        $steps = filter_var($args[0] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (count($args) > 1 || $steps === false) {
            throw new RuntimeException('Usage: rollback [positive batches] [--force]');
        }
        $migrator = new App\Database\Migrator($connection->pdo(), __DIR__ . '/../database/migrations');
        $rolledBack = $migrator->rollback($steps);
        if ($rolledBack === []) {
            echo "Nothing to roll back.\n";
            return 0;
        }
        foreach ($rolledBack as $name) {
            echo Console::color("Rolled back: {$name}", 33) . PHP_EOL;
        }
        return 0;
    },
];
