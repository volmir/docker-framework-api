<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Config;

/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'apikey:generate',
    'description' => 'Generate a plaintext client:key entry: apikey:generate <client>',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        $client = $args[0] ?? '';
        if (count($args) !== 1 || !preg_match(Config::API_CLIENT_PATTERN, $client)) {
            throw new RuntimeException('Usage: apikey:generate <client> (1-64 ASCII letters/digits/_/-, starting with a letter or digit)');
        }
        $key = bin2hex(random_bytes(32));
        echo $client . ':' . $key . PHP_EOL;
        return 0;
    },
];

return $command;
