<?php
declare(strict_types=1);

namespace App\Users;

use App\Database\Connection;
use PDOException;
use RuntimeException;

final class Users
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{id:int, email:string, created_at:string} */
    public function create(string $email, string $password): array
    {
        try {
            $this->connection->run('INSERT INTO users (email, password_hash) VALUES (?, ?)', [$email, password_hash($password, PASSWORD_ARGON2ID)]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new EmailTaken('Email is already taken', previous: $error);
            }
            throw $error;
        }
        return $this->find((int) $this->connection->pdo()->lastInsertId()) ?? throw new RuntimeException('Created user not found');
    }

    /** @return list<array{id:int, email:string, created_at:string}> */
    public function list(int $limit, int $offset): array
    {
        /** @var list<array{id:int, email:string, created_at:string}> $users */
        $users = $this->connection->all('SELECT id, email, created_at FROM users ORDER BY id LIMIT ? OFFSET ?', [$limit, $offset]);
        return $users;
    }

    /** @return array{id:int, email:string, created_at:string}|null */
    public function find(int $id): ?array
    {
        /** @var array{id:int, email:string, created_at:string}|null $user */
        $user = $this->connection->one('SELECT id, email, created_at FROM users WHERE id = ?', [$id]);
        return $user;
    }
}
