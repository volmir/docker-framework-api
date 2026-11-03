<?php

declare(strict_types=1);

use App\Config;
use App\Database\Connection;

/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'env:check',
    'description' => 'Check required environment variables and safety without opening a database connection',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
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

return $command;
