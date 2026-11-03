<?php

declare(strict_types=1);

namespace App\Database;

use App\Config;
use PDO;

final class Connection
{
    private ?PDO $pdo = null;

    /** @param callable(self):mixed $fn */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo()->inTransaction()) {
            throw new \LogicException('Nested transactions are not supported; this connection already has an active transaction');
        }
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } finally {
                throw $error;
            }
        }
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            Config::require('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD');
            $host = Config::get('DB_HOST');
            $port = Config::int('DB_PORT');
            $database = Config::get('DB_NAME');

            $class = Config::isDebug() ? TracedPdo::class : PDO::class;
            $this->pdo = new $class(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                Config::get('DB_USER'),
                Config::get('DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                ] + ($class === TracedPdo::class ? [PDO::ATTR_STATEMENT_CLASS => [TracedStatement::class]] : []),
            );
        }

        return $this->pdo;
    }

    /** @param array<int|string, scalar|null> $params
     * @throws \InvalidArgumentException Arrays/objects are unsupported, including IN (...) lists.
     * @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
    /** @param array<int|string, scalar|null> $params
     * @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return array_values($this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC));
    }
    /** @param array<int|string, scalar|null> $params
     * @return int Affected rows; MySQL ON DUPLICATE KEY UPDATE: insert 1, update 2, unchanged 0. */
    public function run(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params)->rowCount();
    }
    /** @param array<int|string, mixed> $params Arrays/objects are unsupported; expand IN (...) placeholders manually. */
    private function execute(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) {
                throw new \InvalidArgumentException('SQL parameters must be scalar or null; arrays/objects are not supported. Expand IN (...) placeholders manually.');
            }
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue(is_int($key) ? $key + 1 : ':' . ltrim($key, ':'), $value, $type);
        }
        $statement->execute();
        return $statement;
    }
}
