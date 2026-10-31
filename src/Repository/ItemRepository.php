<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;

class ItemRepository
{
    protected Db $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    public function findAll(int $start = 0, int $stop = 20): array
    {
        $sql = 'SELECT * FROM items ORDER BY created_at DESC LIMIT :start,:stop';
        return $this->db->select($sql, [
            'start' => $start,
            'stop' => $stop,
        ]);
    }

    public function find(int $id): array|false
    {
        $sql = 'SELECT * FROM items WHERE id = :id';
        return $this->db->selectOne($sql, ['id' => $id]);
    }

    public function store(array $params = []): bool
    {
        $sql = "INSERT INTO items
            (`name`, `phone`, `key`, `created_at`, `updated_at`)
            VALUES
            (:name, :phone, :key, :created_at, :updated_at)";
        return $this->db->insert($sql, [
            'name' => $params['name'],
            'phone' => $params['phone'],
            'key' => $params['key'],
            'created_at' => $params['created_at'],
            'updated_at' => $params['updated_at'],
        ]);
    }

    public function update(array $params = []): bool
    {
        $sql = "UPDATE `items` SET
            `name` = :name,
            `phone`= :phone,
            `key`= :key,
            `updated_at` = :updated_at
            WHERE `id` = :id";
        return $this->db->update($sql, [
            'id' => (int) $params['id'],
            'name' => $params['name'],
            'phone' => $params['phone'],
            'key' => $params['key'],
            'updated_at' => $params['updated_at'],
        ]);
    }

    public function destroy(int $id): bool
    {
        $sql = 'DELETE FROM items WHERE id = :id';
        return $this->db->delete($sql, ['id' => $id]);
    }

    public function lastInsertId(): string
    {
        return $this->db->lastInsertId();
    }
}
