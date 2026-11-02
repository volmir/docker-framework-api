<?php
declare(strict_types=1);

namespace App\Database;

use App\Config;
use PDO;

final class Connection
{
    private ?PDO $pdo = null;

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
}
