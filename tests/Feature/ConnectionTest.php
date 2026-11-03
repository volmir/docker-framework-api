<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\Connection;
use App\Tests\DatabaseTestCase;
use InvalidArgumentException;
use PDO;
use PDOException;
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

    public function test_one_returns_the_first_row_or_null(): void
    {
        self::assertSame(['value' => 'first'], $this->connection->one('SELECT ? AS value UNION ALL SELECT ?', ['first', 'second']));
        self::assertNull($this->connection->one('SELECT id FROM users WHERE 1 = 0'));
    }

    public function test_all_returns_a_list_in_query_order_and_binds_integer_limit_and_offset(): void
    {
        self::assertSame([], $this->connection->all('SELECT email FROM users'));
        $this->connection->run(
            'INSERT INTO users (email, password_hash) VALUES (?, ?), (?, ?), (?, ?)',
            ['c@example.com', 'hash', 'a@example.com', 'hash', 'b@example.com', 'hash'],
        );
        self::assertSame(
            [['email' => 'a@example.com'], ['email' => 'b@example.com'], ['email' => 'c@example.com']],
            $this->connection->all('SELECT email FROM users ORDER BY email'),
        );
        self::assertSame(
            [['email' => 'b@example.com']],
            $this->connection->all('SELECT email FROM users ORDER BY email LIMIT ? OFFSET ?', [1, 1]),
        );
        self::assertSame(
            [['email' => 'c@example.com']],
            $this->connection->all('SELECT email FROM users ORDER BY email LIMIT :limit OFFSET :offset', ['limit' => 1, ':offset' => 2]),
        );
    }

    public function test_named_and_positional_parameters_keep_php_scalar_types_and_null(): void
    {
        self::assertSame(
            ['id' => 42, 'active' => 1, 'empty_value' => null, 'ratio' => '1.25'],
            $this->connection->one(
                'SELECT :id AS id, :active AS active, :empty AS empty_value, :ratio AS ratio',
                ['id' => 42, ':active' => true, 'empty' => null, ':ratio' => 1.25],
            ),
        );
        self::assertSame(
            ['id' => 7, 'active' => 0, 'empty_value' => null],
            $this->connection->one('SELECT ? AS id, ? AS active, ? AS empty_value', [7, false, null]),
        );
    }

    public function test_run_returns_insert_update_and_delete_affected_rows(): void
    {
        self::assertSame(2, $this->connection->run(
            'INSERT INTO users (email, password_hash) VALUES (?, ?), (?, ?)',
            ['first@example.com', 'hash', 'second@example.com', 'hash'],
        ));
        self::assertSame(1, $this->connection->run(
            'UPDATE users SET password_hash = :hash WHERE email = :email',
            ['hash' => 'changed', ':email' => 'first@example.com'],
        ));
        self::assertSame(0, $this->connection->run('UPDATE users SET password_hash = ? WHERE email = ?', ['changed', 'first@example.com']));
        self::assertSame(2, $this->connection->run('DELETE FROM users'));
        self::assertSame(0, $this->connection->run('DELETE FROM users'));
    }

    public function test_upsert_returns_one_for_insert_two_for_update_and_zero_for_no_change(): void
    {
        $sql = 'INSERT INTO users (email, password_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE password_hash = ?';
        self::assertSame(1, $this->connection->run($sql, ['upsert@example.com', 'first', 'first']));
        self::assertSame(2, $this->connection->run($sql, ['upsert@example.com', 'second', 'second']));
        self::assertSame(0, $this->connection->run($sql, ['upsert@example.com', 'second', 'second']));
        self::assertSame(['password_hash' => 'second'], $this->connection->one('SELECT password_hash FROM users WHERE email = ?', ['upsert@example.com']));
    }

    public function test_arrays_and_objects_are_rejected_by_every_helper_before_execution(): void
    {
        // Reflection deliberately bypasses the documented scalar|null parameter contract.
        foreach (['one', 'all', 'run'] as $method) {
            foreach ([[1, 2], new \stdClass()] as $value) {
                try {
                    (new \ReflectionMethod(Connection::class, $method))->invoke($this->connection, 'SELECT ? AS value', [$value]);
                    self::fail('Expected an invalid SQL parameter');
                } catch (InvalidArgumentException $error) {
                    self::assertStringContainsString('SQL parameters must be scalar or null', $error->getMessage());
                    self::assertStringContainsString('IN (...)', $error->getMessage());
                }
            }
        }
    }

    public function test_invalid_sql_and_duplicate_errors_keep_the_pdo_exception_and_mysql_error_info(): void
    {
        foreach (['one', 'all', 'run'] as $method) {
            try {
                $this->connection->{$method}('THIS IS INVALID SQL');
                self::fail('Expected a MySQL syntax error');
            } catch (PDOException $error) {
                self::assertSame(1064, $error->errorInfo[1] ?? null);
            }
        }
        $sql = 'INSERT INTO users (email, password_hash) VALUES (?, ?)';
        $this->connection->run($sql, ['duplicate@example.com', 'hash']);
        try {
            $this->connection->run($sql, ['duplicate@example.com', 'hash']);
            self::fail('Expected the duplicate key error');
        } catch (PDOException $error) {
            self::assertSame(1062, $error->errorInfo[1] ?? null);
            self::assertSame('23000', $error->getCode());
        }
    }

    public function test_sql_injection_in_a_value_is_stored_and_read_unchanged(): void
    {
        $email = "o'brien@example.com'); DROP TABLE users; --";
        self::assertSame(1, $this->connection->run('INSERT INTO users (email, password_hash) VALUES (:email, :hash)', ['email' => $email, 'hash' => 'hash']));
        self::assertSame(['email' => $email], $this->connection->one('SELECT email FROM users WHERE email = :email', [':email' => $email]));
        self::assertSame([['email' => $email]], $this->connection->all('SELECT email FROM users'));
    }
}
