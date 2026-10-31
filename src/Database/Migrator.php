<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(255) NOT NULL UNIQUE, batch INTEGER NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
        } else {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INT AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        }
    }

    public function migrate(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $applied = $this->pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $pending = array_values(array_filter($files, static fn(string $file): bool => !in_array(pathinfo($file, PATHINFO_FILENAME), $applied, true)));
        if ($pending === []) return [];

        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
        $insert = $this->pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
        $ran = [];
        foreach ($pending as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $migration = require $file;
            if (!is_array($migration) || !isset($migration['up']) || !is_callable($migration['up'])) {
                throw new RuntimeException("Invalid migration: {$file}");
            }
            $migration['up']($this->pdo);
            $insert->execute([$name, $batch]);
            $ran[] = $name;
        }
        return $ran;
    }

    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) return [];
        $maxBatch = $this->pdo->query('SELECT MAX(batch) FROM migrations')->fetchColumn();
        if ($maxBatch === false || $maxBatch === null) return [];

        $select = $this->pdo->prepare('SELECT migration FROM migrations WHERE batch = ? ORDER BY migration DESC');
        $delete = $this->pdo->prepare('DELETE FROM migrations WHERE batch = ?');
        $rolledBack = [];
        for ($batch = (int) $maxBatch; $batch >= 1 && $batch > (int) $maxBatch - $steps; $batch--) {
            $select->execute([$batch]);
            foreach ($select->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $file = rtrim($this->dir, '/') . '/' . $name . '.php';
                if (!is_file($file)) throw new RuntimeException("Migration file not found: {$file}");
                $migration = require $file;
                if (!is_array($migration) || !isset($migration['down']) || !is_callable($migration['down'])) {
                    throw new RuntimeException("Invalid migration: {$file}");
                }
                $migration['down']($this->pdo);
                $rolledBack[] = $name;
            }
            $delete->execute([$batch]);
        }
        return $rolledBack;
    }

    public function status(): array
    {
        $applied = $this->pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(rtrim($this->dir, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $status = [];
        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $status[] = [
                'name' => $name,
                'status' => in_array($name, $applied, true) ? 'applied' : 'pending',
            ];
        }
        return $status;
    }
}
