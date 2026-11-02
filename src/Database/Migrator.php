<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        return $this->locked(function (): array {
            $files = $this->files();
            $this->createTable();
            $applied = ($this->pdo->query('SELECT migration FROM migrations') ?: throw new RuntimeException('Cannot read migrations'))->fetchAll(PDO::FETCH_COLUMN);
            $batch = (int) ($this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations') ?: throw new RuntimeException('Cannot read migration batch'))->fetchColumn();
            $insert = $this->pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
            $ran = [];
            foreach ($files as $name => $migration) {
                if (in_array($name, $applied, true)) {
                    continue;
                }
                $migration['up']($this->pdo);
                $insert->execute([$name, $batch]);
                $ran[] = $name;
            }
            return $ran;
        });
    }

    /** @return list<string> */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new RuntimeException('Rollback steps must be a positive integer');
        }
        return $this->locked(function () use ($steps): array {
            $this->createTable();
            $select = $this->pdo->prepare('SELECT DISTINCT batch FROM migrations ORDER BY batch DESC LIMIT ?');
            $select->bindValue(1, $steps, PDO::PARAM_INT);
            $select->execute();
            $batches = $select->fetchAll(PDO::FETCH_COLUMN);
            $select = $this->pdo->prepare('SELECT migration FROM migrations WHERE batch = ? ORDER BY migration DESC');
            $delete = $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?');
            $rolledBack = [];
            foreach ($batches as $batch) {
                $select->execute([$batch]);
                foreach ($select->fetchAll(PDO::FETCH_COLUMN) as $name) {
                    $migration = $this->load(rtrim($this->dir, '/') . '/' . $name . '.php');
                    $migration['down']($this->pdo);
                    $delete->execute([$name]);
                    $rolledBack[] = $name;
                }
            }
            return $rolledBack;
        });
    }

    /** @return list<array{name:string, status:'applied'|'pending'}> */
    public function status(): array
    {
        $files = $this->files();
        $exists = ($this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations'") ?: throw new RuntimeException('Cannot inspect migrations table'))->fetchColumn();
        $applied = (int) $exists > 0
            ? ($this->pdo->query('SELECT migration FROM migrations') ?: throw new RuntimeException('Cannot read migrations'))->fetchAll(PDO::FETCH_COLUMN)
            : [];
        $status = [];
        foreach (array_keys($files) as $name) {
            $status[] = ['name' => $name, 'status' => in_array($name, $applied, true) ? 'applied' : 'pending'];
        }
        return $status;
    }

    private function createTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT UNSIGNED NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /** @return array<string, array{up:callable(PDO):void, down:callable(PDO):void}> */
    private function files(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $migrations = [];
        foreach ($files as $file) {
            $migrations[pathinfo($file, PATHINFO_FILENAME)] = $this->load($file);
        }
        return $migrations;
    }

    /** @return array{up:callable(PDO):void, down:callable(PDO):void} */
    private function load(string $file): array
    {
        if (!preg_match('/\A[0-9]{14}_[a-z][a-z0-9_]*\.php\z/', basename($file))) {
            throw new RuntimeException('Invalid migration filename: ' . basename($file));
        }
        if (!is_file($file)) {
            throw new RuntimeException('Migration file not found: ' . basename($file));
        }
        $migration = require $file;
        if (!is_array($migration) || !is_callable($migration['up'] ?? null) || !is_callable($migration['down'] ?? null)) {
            throw new RuntimeException('Invalid migration (callable up/down required): ' . basename($file));
        }
        return ['up' => $migration['up'], 'down' => $migration['down']];
    }

    /** @template T
     * @param callable():T $operation
     * @return T */
    private function locked(callable $operation): mixed
    {
        $name = 'migrations:' . substr(hash('sha256', (string) ($this->pdo->query('SELECT DATABASE()') ?: throw new RuntimeException('Cannot read database name'))->fetchColumn()), 0, 48);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire migration lock');
        }
        try {
            return $operation();
        } finally {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$name]);
        }
    }
}
