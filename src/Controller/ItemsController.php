<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\BaseController;
use App\Core\Request;
use App\Service\ItemService;

class ItemsController extends BaseController
{
    protected ItemService $itemService;

    public function __construct()
    {
        parent::__construct();
        $this->itemService = new ItemService();
    }

    public function index(): void
    {
        if ($this->request->requestMethod != Request::METHOD_GET) {
            $this->content = ['error' => 'Method Not Allowed'];
        } else {
            $this->content = $this->itemService->findAll();
        }
        $this->send();
    }

    public function show(): void
    {
        if ($this->request->requestMethod != Request::METHOD_GET) {
            $this->content = ['error' => 'Method Not Allowed'];
        } else {
            $this->content = $this->itemService->find((int) ($this->request->queryData['id'] ?? 0));
        }
        $this->send();
    }

    public function store(): void
    {
        if ($this->request->requestMethod != Request::METHOD_POST) {
            $this->content = ['error' => 'Method Not Allowed'];
        } else {
            $this->content = $this->itemService->store($this->request->formData);
        }
        $this->send();
    }

    public function update(): void
    {
        if (!in_array($this->request->requestMethod, [Request::METHOD_PUT, Request::METHOD_PATCH])) {
            $this->content = ['error' => 'Method Not Allowed'];
        } else {
            $this->content = $this->itemService->update($this->request->formData);
        }
        $this->send();
    }

    public function destroy(): void
    {
        if ($this->request->requestMethod != Request::METHOD_DELETE) {
            $this->content = ['error' => 'Method Not Allowed'];
        } else {
            $this->content = $this->itemService->destroy((int) ($this->request->queryData['id'] ?? 0));
        }
        $this->send();
    }
}
