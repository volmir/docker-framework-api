<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class Seeder
{
    public function __construct(private PDO $pdo, private string $dir) {}

    /** @return list<string> */
    public function run(?string $name = null): array
    {
        if ($name !== null && !preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/', $name)) {
            throw new RuntimeException('Invalid seeder name');
        }
        $files = $name === null
            ? (glob(rtrim($this->dir, '/') . '/*.php') ?: [])
            : [rtrim($this->dir, '/') . '/' . $name . '.php'];
        sort($files, SORT_STRING);
        /** @var array<string, array{run:callable(PDO):void}> $seeders */
        $seeders = [];
        foreach ($files as $file) {
            if (!preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\.php\z/', basename($file))) {
                throw new RuntimeException('Invalid seeder filename: ' . basename($file));
            }
            if (!is_file($file)) {
                throw new RuntimeException('Seeder not found: ' . basename($file));
            }
            $seeder = require $file;
            if (!is_array($seeder) || !is_callable($seeder['run'] ?? null)) {
                throw new RuntimeException('Invalid seeder (callable run required): ' . basename($file));
            }
            $seeders[pathinfo($file, PATHINFO_FILENAME)] = $seeder;
        }
        foreach ($seeders as $seeder) {
            $seeder['run']($this->pdo);
        }
        return array_keys($seeders);
    }
}
