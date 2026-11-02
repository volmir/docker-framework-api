<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Config;

return [
    'name' => 'apikey:generate',
    'description' => 'Generate an API key and a client:hash entry: apikey:generate <client>',
    'execute' => static function (array $args, Connection $connection): int {
        $client = $args[0] ?? '';
        if (count($args) !== 1 || !preg_match(Config::API_CLIENT_PATTERN, $client)) {
            throw new RuntimeException('Usage: apikey:generate <client> (1-64 ASCII letters/digits/_/-, starting with a letter or digit)');
        }
        $key = bin2hex(random_bytes(32));
        echo "API key (shown once): {$key}\n";
        echo $client . ':' . hash('sha256', $key) . PHP_EOL;
        return 0;
    },
];
