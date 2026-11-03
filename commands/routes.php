<?php

declare(strict_types=1);

use App\Console\Console;
use App\Database\Connection;

/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'routes',
    'description' => 'Show routes and middleware; --check validates configuration',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        if ($args !== [] && $args !== ['--check']) {
            throw new RuntimeException('Usage: routes [--check]');
        }
        $check = $args === ['--check'];
        $file = __DIR__ . '/../src/routes.php';
        $routes = require $file;
        $global = require __DIR__ . '/../src/middleware.php';
        $source = $check ? file_get_contents($file) : '';
        if ($source === false) {
            throw new RuntimeException('Cannot read routes.php');
        }
        $result = Console::routesTable($routes, $global, $check, $check ? Console::routeKeys($source) : null);
        echo $result['output'];
        foreach ($result['errors'] as $error) {
            echo $error . PHP_EOL;
        }
        return $result['errors'] === [] ? 0 : 1;
    },
];

return $command;
