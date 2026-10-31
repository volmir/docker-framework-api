<?php
declare(strict_types=1);

namespace App\Core;

class Application
{
    public string $environment = '';

    public function run(): void
    {
        $this->environment = getenv('APP_ENV') ?: (PHP_SAPI === 'cli' ? 'develop' : Environment::get());
        $router = new Router();
        $router->dispatch();
    }

}
