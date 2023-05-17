<?php

namespace App\Core;

use App\Core\Request;
use App\Core\Response;
use App\Core\Db;
use App\Auth\Guard;

class BaseController
{
    /**
     * @var Request 
     */
    protected $request;

    /**
     * @var Response 
     */
    protected $response;

    /**
     * @var \PDO 
     */
    protected $pdo;

    public function __construct() 
    {
        $this->request = new Request;
        $this->response = new Response;
        $this->guard();
    }

    public function initDb()
    {
        $this->pdo = Db::getInstance();
    }

    protected function guard()
    {
        $guard = new Guard();
        if (!$guard->authToken($this->request->getHeaders())) {
            $this->response->setHeader('HTTP/1.1 401 Unauthorized');
            $this->response->setContent(['error' => 'Unauthorized']);
            $this->response->outputJson();
            exit();
        }
    }
}