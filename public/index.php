<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Middleware;
use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Router;

require_once __DIR__ . '/../src/Errors.php';
App\Errors::register();
require __DIR__ . '/../vendor/autoload.php';
App\Config::boot();

$request = Request::fromGlobals();
$router = new Router(require __DIR__ . '/../src/routes.php', new Connection());
/** @var list<class-string<Middleware>> $middlewareClasses */
$middlewareClasses = require __DIR__ . '/../src/middleware.php';
$middleware = array_map(static fn($class): Middleware => new $class(), $middlewareClasses);
$response = Pipeline::run(
    $middleware,
    $request,
    $router->handle(...),
);
$response->send();
