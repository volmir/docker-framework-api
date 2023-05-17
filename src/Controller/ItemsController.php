<?php

namespace App\Controller;

use \PDO;
use \App\Core\BaseController;
use App\Core\Request;

class ItemsController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        $this->initDb();
    }

    public function index()
    {
        if ($this->request->requestMethod != Request::METHOD_GET) {
            $this->response->setHeader('HTTP/1.1 405 Method Not Allowed');
            $content = ['error' => 'Method Not Allowed'];   
        } else {
            $sql = 'SELECT * FROM items LIMIT 0,20';
            $content = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
        $this->response->setContent($content);
        $this->response->outputJson();
    }

    public function show()
    {
        if ($this->request->requestMethod != Request::METHOD_GET) {
            $this->response->setHeader('HTTP/1.1 405 Method Not Allowed');
            $content = ['error' => 'Method Not Allowed'];
        } elseif (!empty($this->request->queryData['id']) && $this->request->queryData['id'] > 0) {         
            $sql = "SELECT * FROM items WHERE id = :id";
            $query = $this->pdo->prepare($sql);
            $query->bindParam(':id', $this->request->queryData['id']);
            $query->execute();
            $content = $query->fetch(PDO::FETCH_ASSOC);
        } else {
            $this->response->setHeader('HTTP/1.1 400 Bad Request');
            $content = ['error' => 'Bad Request. Item ID not found'];
        }
        $this->response->setContent($content);
        $this->response->outputJson();
    }  

    public function store()
    {
        if ($this->request->requestMethod != Request::METHOD_POST) {
            $this->response->setHeader('HTTP/1.1 405 Method Not Allowed');
            $content = ['error' => 'Method Not Allowed'];
        } elseif (
            !empty($this->request->formData['name']) &&
            !empty($this->request->formData['phone']) &&
            !empty($this->request->formData['key'])
        ) {
            $created_at = date('Y-m-d H:i:s');
            $updated_at = date('Y-m-d H:i:s');
            $name = substr($this->request->formData['name'], 0, 255);
            $phone = substr($this->request->formData['phone'], 0, 15);
            $key = substr($this->request->formData['key'], 0, 25);
            $sql = "INSERT INTO items 
                (`name`, `phone`, `key`, `created_at`, `updated_at`) 
                VALUES 
                (:name, :phone, :key, :created_at, :updated_at)";
            $query = $this->pdo->prepare($sql);
            $query->bindParam(':name', $name);
            $query->bindParam(':phone', $phone);
            $query->bindParam(':key', $key);
            $query->bindParam(':created_at', $created_at);
            $query->bindParam(':updated_at', $updated_at);            
            if ($query->execute()) {
                $sql = 'SELECT LAST_INSERT_ID()';
                $query = $this->pdo->prepare($sql);
                $query->execute();
                $last_insert_id = $query->fetch(PDO::FETCH_ASSOC);
                $content = [
                    'success' => 'Created',
                    'id' => $last_insert_id['LAST_INSERT_ID()'],
                ];
                $this->response->setHeader('HTTP/1.1 201 Created');
            } else {
                $content = ['error' => 'DB error'];
            }           
        } else {
            $this->response->setHeader('HTTP/1.1 400 Bad Request');
            $content = ['error' => 'Bad Request. Item ID not found'];
        }
        $this->response->setContent($content);
        $this->response->outputJson();
    }    

    public function update()
    {
        if (!in_array($this->request->requestMethod, [Request::METHOD_PUT, Request::METHOD_PATCH])) {
            $this->response->setHeader('HTTP/1.1 405 Method Not Allowed');
            $content = ['error' => 'Method Not Allowed'];
        } elseif (
                !empty($this->request->formData['id']) &&
                !empty($this->request->formData['name']) &&
                !empty($this->request->formData['phone']) &&
                !empty($this->request->formData['key'])
        ) { 
            $sql = "SELECT * FROM items WHERE id = :id";
            $query = $this->pdo->prepare($sql);
            $query->bindParam(':id', $this->request->formData['id']);
            $query->execute();
            $item = $query->fetch(PDO::FETCH_ASSOC);            
            if (!empty($item)) {
                $updated_at = date('Y-m-d H:i:s');
                $id = $this->request->formData['id'];
                $name = substr($this->request->formData['name'], 0, 255);
                $phone = substr($this->request->formData['phone'], 0, 15);
                $key = substr($this->request->formData['key'], 0, 25);
                $sql = "UPDATE `items` SET 
                    `name` = :name, 
                    `phone`= :phone, 
                    `key`= :key, 
                    `updated_at` = :updated_at 
                    WHERE `id` = :id";
                $query = $this->pdo->prepare($sql);
                $query->bindParam(':id', $id);
                $query->bindParam(':name', $name);
                $query->bindParam(':phone', $phone);
                $query->bindParam(':key', $key);
                $query->bindParam(':updated_at', $updated_at);            
                if ($query->execute()) {    
                    $content = ['success' => 'Updated'];
                } else {
                    $content = ['error' => 'DB error'];
                }
            } else {
                $content = ['error' => 'DB error'];
            }
        } else {
            $this->response->setHeader('HTTP/1.1 400 Bad Request');
            $content = ['error' => 'Bad Request. Item ID not found'];
        }
        $this->response->setContent($content);
        $this->response->outputJson();
    }

    public function destroy()
    {
        if ($this->request->requestMethod != Request::METHOD_DELETE) {
            $this->response->setHeader('HTTP/1.1 405 Method Not Allowed');
            $content = ['error' => 'Method Not Allowed'];
        } elseif (!empty($this->request->queryData['id']) && $this->request->queryData['id'] > 0) {        
            $sql = "DELETE FROM items WHERE id = :id";
            $query = $this->pdo->prepare($sql);
            $query->bindParam(':id', $this->request->queryData['id']);
            if ($query->execute()) {
                $content = ['success' => 'Deleted'];
            }
        } else {
            $this->response->setHeader('HTTP/1.1 400 Bad Request');
            $content = ['error' => 'Bad Request. Item ID not found'];
        }
        $this->response->setContent($content);
        $this->response->outputJson();
    }
}