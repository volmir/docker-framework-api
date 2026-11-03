<?php

declare(strict_types=1);

use App\Console\Generator;
use App\Database\Connection;

/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'make:middleware', 'description' => 'Generate middleware without registering it',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        if (count($args) !== 1 || !preg_match('/\A[A-Z][A-Za-z0-9]*\z/', $args[0])) {
            throw new RuntimeException('Usage: make:middleware <StudlyCaseName>');
        }
        $name = $args[0];
        if (PhpToken::tokenize('<?php ' . $name)[1]->id !== T_STRING || in_array(strtolower($name), ['self', 'parent', 'int', 'float', 'bool', 'string', 'null', 'true', 'false', 'void', 'iterable', 'object', 'mixed', 'never'], true)) {
            throw new RuntimeException('Invalid PHP class name: ' . $name);
        }
        if (class_exists('App\\Middleware\\' . $name)) {
            throw new RuntimeException('Middleware class already exists: ' . $name);
        }
        $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Middleware;

final class __NAME__ implements \App\Http\Middleware
{
    /** @param callable(\App\Http\Request):\App\Http\Response $next */
    public function handle(\App\Http\Request $request, callable $next): \App\Http\Response
    {
        return $next($request);
    }
}
PHP;
        echo (new Generator(dirname(__DIR__)))->write('src/Middleware/' . $name . '.php', str_replace('__NAME__', $name, $content) . PHP_EOL) . PHP_EOL;
        echo "Add App\\Middleware\\{$name}::class to src/middleware.php (global) or the third route element in src/routes.php (per route).\n";
        return 0;
    },
];
return $command;
