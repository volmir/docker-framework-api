<?php
declare(strict_types=1);
use App\Console\Generator;
use App\Database\Connection;
/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'make:controller', 'description' => 'Generate a controller and print routes to add manually',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        if (!preg_match('/\A(?:[A-Z][A-Za-z0-9]*|[a-z][a-z0-9]*(?:_[a-z0-9]+)*)\z/', $args[0] ?? '')) { throw new RuntimeException('Usage: make:controller <Name> [action ...]'); }
        $name = preg_replace('/Controller\z/', '', Generator::studly($args[0])) ?? '';
        if (!preg_match('/\A[A-Z][A-Za-z0-9]*\z/', $name)) { throw new RuntimeException('Invalid controller name'); }
        $actions = array_slice($args, 1) ?: ['index'];
        foreach ($actions as $action) { if (!preg_match('/\A[a-z][A-Za-z0-9]*\z/', $action)) { throw new RuntimeException('Invalid controller action: ' . $action); } }
        if (count(array_unique(array_map('strtolower', $actions))) !== count($actions)) { throw new RuntimeException('Duplicate controller action'); }
        if (class_exists('App\\Controller\\' . $name . 'Controller')) { throw new RuntimeException('Controller class already exists: ' . $name . 'Controller'); }
        $method = <<<'PHP'
    public function __ACTION__(Request $request): Response
    { return Response::json(['data' => []]); }
PHP;
        $content = <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Controller;
use App\Database\Connection;
use App\Http\{Request, Response};
final class __NAME__Controller {
    public function __construct(Connection $_connection) {}
__METHODS__
}
PHP;
        $methods = implode("\n", array_map(static fn(string $action): string => str_replace('__ACTION__', $action, $method), $actions));
        echo (new Generator(dirname(__DIR__)))->write('src/Controller/' . $name . 'Controller.php', strtr($content, ['__NAME__' => $name, '__METHODS__' => $methods]) . PHP_EOL) . PHP_EOL;
        echo "Add use App\\Controller\\{$name}Controller; and use App\\Middleware\\{ApiKeyAuth, RequireJson}; to src/routes.php, then run `make routes-check`:\n";
        foreach ($actions as $action) {
            $verb = match ($action) { 'create' => 'POST', 'update' => 'PATCH', 'delete' => 'DELETE', default => 'GET' }; $path = '/' . Generator::snake($name) . 's' . (in_array($action, ['index', 'create', 'update', 'delete'], true) ? '' : '/' . $action);
            $layers = in_array($verb, ['POST', 'PUT', 'PATCH'], true) ? 'ApiKeyAuth::class, RequireJson::class' : 'ApiKeyAuth::class'; echo "'{$verb} {$path}' => [{$name}Controller::class, '{$action}', [{$layers}]],\n";
        } return 0;
    },
]; return $command;
