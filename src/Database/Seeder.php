<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class Seeder
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    public function run(?string $name = null): array
    {
        if ($name !== null) {
            $file = rtrim($this->dir, '/') . '/' . $name . '.php';
            if (!is_file($file)) {
                throw new RuntimeException("Seeder not found: {$name}");
            }
            $files = [$file];
        } else {
            $files = glob(rtrim($this->dir, '/') . '/*.php') ?: [];
            sort($files, SORT_STRING);
        }

        $ran = [];
        foreach ($files as $file) {
            $seeder = require $file;
            $seeder['run']($this->pdo);
            $ran[] = pathinfo($file, PATHINFO_FILENAME);
        }
        return $ran;
    }
}
