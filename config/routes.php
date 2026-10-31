<?php
declare(strict_types=1);

/**
 * Route map: exact URL path => "ControllerName/methodName".
 * ControllerName resolves to App\Controller\<ControllerName>.
 * Paths must match exactly (no placeholders). Use query string for parameters.
 */
return [
    '/health' => 'HealthController/index',
    '/users' => 'UserController/index',
    '/users/create' => 'UserController/create',
    '/users/show' => 'UserController/show',
];
