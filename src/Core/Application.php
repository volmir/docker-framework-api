<?php

namespace App\Core;

use App\Core\Router;
use App\Core\Environment;
 
class Application 
{
    public $config;
    public $environment;

    public function run() 
    {
        $this->environment = Environment::get();
        $this->config = require(__DIR__ . '/../../config/main.php');
        $this->setParams();

        $router = new Router();
        $router->dispatch();
    }    

    protected function setParams()
    {
        define('DB_HOST', $this->config['db'][$this->environment]['host']);
        define('DB_PORT', $this->config['db'][$this->environment]['port']);
        define('DB_DBNAME', $this->config['db'][$this->environment]['dbname']);
        define('DB_USERNAME', $this->config['db'][$this->environment]['username']);
        define('DB_PASSWORD', $this->config['db'][$this->environment]['password']);
    }    
}