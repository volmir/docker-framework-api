<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\Connection;
use App\Tests\DatabaseTestCase;
use PDO;
use RuntimeException;

final class ConnectionTest extends DatabaseTestCase
{
    public function test_configuration_is_only_required_when_pdo_is_requested(): void
    {
        $hadHost = array_key_exists('DB_HOST', $_ENV);
        $envHost = $_ENV['DB_HOST'] ?? null;
        $processHost = getenv('DB_HOST');
        unset($_ENV['DB_HOST']);
        putenv('DB_HOST');

        try {
            $connection = new Connection();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Missing environment variables: DB_HOST');
            $connection->pdo();
        } finally {
            if ($hadHost) {
                $_ENV['DB_HOST'] = $envHost;
            }
            putenv($processHost === false ? 'DB_HOST' : "DB_HOST={$processHost}");
        }
    }

    public function test_pdo_is_reused_and_has_the_required_mysql_settings(): void
    {
        $connection = new Connection();
        $pdo = $connection->pdo();

        self::assertSame($pdo, $connection->pdo());
        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        self::assertFalse($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        self::assertSame(['charset' => 'utf8mb4'], ($pdo->query('SELECT @@character_set_connection AS charset') ?: self::fail('Database query failed'))->fetch());
    }

    public function test_connection_instances_do_not_share_pdo(): void
    {
        $first = new Connection();
        $second = new Connection();

        self::assertNotSame($first->pdo(), $second->pdo());
    }
}
