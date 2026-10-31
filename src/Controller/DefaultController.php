<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\BaseController;

class DefaultController extends BaseController
{
    public function index(): void
    {
        $this->content = [
            'time' => date('Y-m-d H:i:s'),
        ];
        $this->send();
    }
}
