<?php

namespace App\Repository;

use \PDO;
use App\Core\Db;

class ItemRepository
{
    /**
     * @var \PDO 
     */
    protected $pdo;

    public function __construct()
    {
        $this->pdo = Db::getInstance();
    }

    public function findAll(int $start = 0, int $stop = 20) 
    {
        $sql = 'SELECT * FROM items ORDER BY created_at DESC LIMIT :start,:stop';
        $query = $this->pdo->prepare($sql);
        $query->bindParam(':start', $start, PDO::PARAM_INT);
        $query->bindParam(':stop', $stop, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id) 
    {
        $sql = "SELECT * FROM items WHERE id = :id";
        $query = $this->pdo->prepare($sql);
        $query->bindParam(':id', $id, PDO::PARAM_INT);
        $query->execute();
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function store(array $params = [])
    {
        $sql = "INSERT INTO items 
            (`name`, `phone`, `key`, `created_at`, `updated_at`) 
            VALUES 
            (:name, :phone, :key, :created_at, :updated_at)";
        $query = $this->pdo->prepare($sql);
        $query->bindParam(':name', $params['name']);
        $query->bindParam(':phone', $params['phone']);
        $query->bindParam(':key', $params['key']);
        $query->bindParam(':created_at', $params['created_at']); 
        $query->bindParam(':updated_at', $params['updated_at']);
        return $query->execute();       
    }
    
    public function update(array $params = [])
    {
        $sql = "UPDATE `items` SET 
            `name` = :name, 
            `phone`= :phone, 
            `key`= :key, 
            `updated_at` = :updated_at 
            WHERE `id` = :id";
        $query = $this->pdo->prepare($sql);
        $query->bindParam(':id', $params['id'], PDO::PARAM_INT);
        $query->bindParam(':name', $params['name']);
        $query->bindParam(':phone', $params['phone']);
        $query->bindParam(':key', $params['key']);
        $query->bindParam(':updated_at', $params['updated_at']);            
        return $query->execute();
    }

    public function destroy(int $id) 
    {
        $sql = "DELETE FROM items WHERE id = :id";
        $query = $this->pdo->prepare($sql);
        $query->bindParam(':id', $id, PDO::PARAM_INT);
        return $query->execute();
    }

    public function lastInsertId()
    {
        $sql = 'SELECT LAST_INSERT_ID()';
        $query = $this->pdo->prepare($sql);
        $query->execute();
        return $query->fetch(PDO::FETCH_ASSOC);        
    }
}
