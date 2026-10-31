<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Router;

/** @var PDO $pdo */
$pdo = require __DIR__ . '/../src/bootstrap.php';

/** @var array<string, string> $routes */
$routes = require __DIR__ . '/../config/routes.php';

$request = Request::fromGlobals();
$router = new Router($routes, $pdo);
$response = $router->dispatch($request);
$response->send();
