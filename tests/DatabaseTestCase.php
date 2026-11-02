<?php
declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::get('APP_ENV') !== 'test' || Config::get('DB_HOST') !== 'db-test' || Config::get('DB_NAME') !== 'app_test') {
            throw new LogicException('Database tests require the isolated db-test service');
        }
        $this->connection = new Connection();
        $this->pdo = $this->connection->pdo();
        (new Migrator($this->pdo, __DIR__ . '/../database/migrations'))->migrate();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }
}
