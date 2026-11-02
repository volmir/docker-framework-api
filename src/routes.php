<?php
declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\UserController;
use App\Middleware\ApiKeyAuth;
use App\Middleware\RequireJson;

return [
    'GET /health' => [HealthController::class, 'index'],
    'GET /users' => [UserController::class, 'index', [ApiKeyAuth::class]],
    'GET /users/show' => [UserController::class, 'show', [ApiKeyAuth::class]],
    'POST /users' => [UserController::class, 'create', [ApiKeyAuth::class, RequireJson::class]],
];
