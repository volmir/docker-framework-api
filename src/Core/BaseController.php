<?php

namespace App\Core;

use App\Core\Request;
use App\Core\Response;
use App\Auth\Guard;

class BaseController
{
    protected Request $request;
    protected Response $response;
    protected mixed $content = [];

    public function __construct() 
    {
        $this->request = new Request;
        $this->response = new Response;
        $this->guard();
    }

    public function send()
    {
        $this->checkErrors();
        $this->response->setContent($this->content);
        $this->response->outputJson();        
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

    protected function checkErrors() 
    {
        if (isset($this->content['error'])) {
            if ($this->content['error'] == 'Bad Request') {
                $this->response->setHeader('HTTP/1.1 400 Bad Request');
            } elseif ($this->content['error'] == 'Method Not Allowed') {
                $this->response->setHeader('HTTP/1.1 405 Method Not Allowed');
            } else {
                $this->response->setHeader('HTTP/1.1 500 Internal Server Error');
            }
        } elseif (isset($this->content['success'])) {
            if ($this->content['success'] == 'Created') {
                $this->response->setHeader('HTTP/1.1 201 Created');
            }
        }         
    }   
}