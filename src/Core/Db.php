<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use InvalidArgumentException;

/**
 * Small PDO wrapper that keeps SQL values separate from query text.
 */
class Db
{
    private static ?self $instance = null;

    private PDO $pdo;

    private function __construct()
    {
        $dsn = 'mysql:host=' . DB_HOST
            . ';port=' . DB_PORT
            . ';dbname=' . DB_DBNAME
            . ';charset=utf8mb4';

        $this->pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** @return array<int, array<string, mixed>> */
    public function select(string $sql, array $params = []): array
    {
        return $this->prepareAndExecute($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|false */
    public function selectOne(string $sql, array $params = []): array|false
    {
        return $this->prepareAndExecute($sql, $params)->fetch();
    }

    public function insert(string $sql, array $params = []): bool
    {
        $this->prepareAndExecute($sql, $params);
        return true;
    }

    public function update(string $sql, array $params = []): bool
    {
        $this->prepareAndExecute($sql, $params);
        return true;
    }

    public function delete(string $sql, array $params = []): bool
    {
        $this->prepareAndExecute($sql, $params);
        return true;
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    private function prepareAndExecute(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        foreach ($params as $name => $value) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('SQL parameters must use named placeholders.');
            }

            $placeholder = ':' . ltrim($name, ':');
            $statement->bindValue($placeholder, $value, $this->pdoType($value));
        }

        $statement->execute();

        return $statement;
    }

    private function pdoType(mixed $value): int
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }

        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        return PDO::PARAM_STR;
    }
}
