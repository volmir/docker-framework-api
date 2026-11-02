<?php
declare(strict_types=1);

use App\Config;
use App\Database\Connection;

return [
    'name' => 'env:check',
    'description' => 'Check required environment variables and safety without opening a database connection',
    'execute' => static function (array $args, Connection $connection): int {
        try {
            Config::boot();
        } catch (RuntimeException $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            return 1;
        }
        echo 'Environment ' . Config::env() . " is safe.\n";
        return 0;
    },
];
