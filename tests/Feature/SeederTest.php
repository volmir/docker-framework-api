<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\Seeder;
use App\Tests\DatabaseTestCase;
use PDO;
use RuntimeException;

final class SeederTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/seeders-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        foreach (['users' => 'alice', 'users_extra' => 'bob'] as $name => $user) {
            file_put_contents($this->dir . '/' . $name . '.php', '<?php return ["run" => static function (PDO $pdo): void { $pdo->exec("INSERT INTO users (email, password_hash) VALUES (\'' . $user . '@example.com\', \'test-hash\')"); }];');
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach (glob($this->dir . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        } finally {
            parent::tearDown();
        }
    }

    public function test_all_seeders_run_in_order_and_one_can_be_selected(): void
    {
        $seeder = new Seeder($this->pdo, $this->dir);
        self::assertSame(['users'], $seeder->run('users'));
        self::assertSame(['alice@example.com'], ($this->pdo->query('SELECT email FROM users') ?: self::fail('Database query failed'))->fetchAll(PDO::FETCH_COLUMN));
        $this->pdo->exec('DELETE FROM users');
        self::assertSame(['users', 'users_extra'], $seeder->run());
        self::assertSame(2, (int) ($this->pdo->query('SELECT COUNT(*) FROM users') ?: self::fail('Database query failed'))->fetchColumn());
    }

    public function test_demo_seed_is_idempotent_and_keeps_existing_passwords_and_dates(): void
    {
        $seeder = new Seeder($this->pdo, __DIR__ . '/../../database/seeds');
        self::assertSame(['users'], $seeder->run());
        $statement = $this->pdo->query('SELECT * FROM users ORDER BY id') ?: self::fail('Database query failed');
        $first = $statement->fetchAll();
        $seeder->run();
        $statement->execute();
        self::assertSame($first, $statement->fetchAll());
        self::assertCount(2, $first);
        self::assertNotSame($first[0]['password_hash'], $first[1]['password_hash']);
        foreach ($first as $user) {
            self::assertSame('argon2id', password_get_info($user['password_hash'])['algoName']);
        }
    }

    public function test_users_schema_has_expected_types_defaults_engine_and_unique_email(): void
    {
        $columns = ($this->pdo->query('SHOW COLUMNS FROM users') ?: self::fail('Database query failed'))->fetchAll(PDO::FETCH_UNIQUE);
        self::assertSame('bigint unsigned', $columns['id']['Type']);
        self::assertSame('auto_increment', $columns['id']['Extra']);
        self::assertSame('PRI', $columns['id']['Key']);
        foreach (['email', 'password_hash'] as $field) {
            self::assertSame('varchar(255)', $columns[$field]['Type']);
            self::assertSame('NO', $columns[$field]['Null']);
        }
        self::assertSame('UNI', $columns['email']['Key']);
        foreach (['created_at', 'updated_at'] as $field) {
            self::assertSame('datetime', $columns[$field]['Type']);
            self::assertSame('NO', $columns[$field]['Null']);
            self::assertSame('CURRENT_TIMESTAMP', $columns[$field]['Default']);
        }
        self::assertStringContainsString('on update CURRENT_TIMESTAMP', $columns['updated_at']['Extra']);
        $table = ($this->pdo->query("SELECT ENGINE, TABLE_COLLATION FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'") ?: self::fail('Database query failed'))->fetch();
        self::assertSame('InnoDB', $table['ENGINE']);
        self::assertStringStartsWith('utf8mb4_', $table['TABLE_COLLATION']);
    }

    public function test_missing_invalid_and_traversal_seed_names_are_rejected(): void
    {
        $seeder = new Seeder($this->pdo, $this->dir);
        foreach (['missing', '../users', 'BadName', 'users.php'] as $name) {
            try {
                $seeder->run($name);
                self::fail('Expected invalid seeder');
            } catch (RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }

    public function test_invalid_files_and_non_callable_run_are_rejected_before_any_seed_runs(): void
    {
        foreach (['Bad.php' => '[]', 'zz_bad.php' => "['run' => null]", 'zz_wrong.php' => 'false'] as $name => $definition) {
            file_put_contents($this->dir . '/' . $name, '<?php return ' . $definition . ';');
            try {
                (new Seeder($this->pdo, $this->dir))->run();
                self::fail('Expected invalid seeder');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Invalid seeder', $error->getMessage());
            } finally {
                unlink($this->dir . '/' . $name);
            }
            self::assertSame(0, (int) ($this->pdo->query('SELECT COUNT(*) FROM users') ?: self::fail('Database query failed'))->fetchColumn());
        }
    }
}
