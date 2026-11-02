<?php
declare(strict_types=1);

use App\Database\Connection;
use function App\Console\routeKeys;
use function App\Console\routesTable;

return [
    'name' => 'routes',
    'description' => 'Show routes and middleware; --check validates configuration',
    'execute' => static function (array $args, Connection $connection): int {
        if ($args !== [] && $args !== ['--check']) { throw new RuntimeException('Usage: routes [--check]'); }
        require_once __DIR__ . '/../src/Console/routes.php';
        $check = $args === ['--check'];
        $file = __DIR__ . '/../src/routes.php';
        $routes = require $file;
        $global = require __DIR__ . '/../src/middleware.php';
        $source = $check ? file_get_contents($file) : '';
        if ($source === false) { throw new RuntimeException('Cannot read routes.php'); }
        $result = routesTable($routes, $global, $check, $check ? routeKeys($source) : null);
        echo $result['output'];
        foreach ($result['errors'] as $error) { echo $error . PHP_EOL; }
        return $result['errors'] === [] ? 0 : 1;
    },
];
