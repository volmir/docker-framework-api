<?php
declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\UserController;
use App\Http\Middleware;
use App\Middleware\ApiKeyAuth;
use App\Middleware\RequireJson;

/** @var array<string, array{0: class-string, 1: string, 2?: list<class-string<Middleware>>}> $routes */
$routes = [
    'GET /health' => [HealthController::class, 'index'],
    'GET /users' => [UserController::class, 'index', [ApiKeyAuth::class]],
    'GET /users/show' => [UserController::class, 'show', [ApiKeyAuth::class]],
    'POST /users' => [UserController::class, 'create', [ApiKeyAuth::class, RequireJson::class]],
    // make:controller: new routes are inserted above this line
];

return $routes;
