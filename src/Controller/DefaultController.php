<?php

namespace App\Controller;

use \App\Core\BaseController;

class DefaultController extends BaseController
{
    public function index()
    {
        $content = [
            'time' => date('Y-m-d H:i:s'),
        ];
        $this->response->setContent($content);
        $this->response->outputJson();
    }

}