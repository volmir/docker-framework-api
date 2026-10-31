<?php
declare(strict_types=1);

namespace App\Core;

class Application
{
    /** @var array<string, mixed> */
    public array $config = [];
    public string $environment = '';

    public function run(): void
    {
        $this->environment = Environment::get();
        $this->config = require __DIR__ . '/../../config/main.php';
        $this->setParams();

        $router = new Router();
        $router->dispatch();
    }

    protected function setParams(): void
    {
        define('DB_HOST', $this->config['db'][$this->environment]['host']);
        define('DB_PORT', $this->config['db'][$this->environment]['port']);
        define('DB_DBNAME', $this->config['db'][$this->environment]['dbname']);
        define('DB_USERNAME', $this->config['db'][$this->environment]['username']);
        define('DB_PASSWORD', $this->config['db'][$this->environment]['password']);
    }
}
