<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigratorTest extends TestCase
{
    private PDO $pdo;
    private string $dir;
    private Migrator $migrator;

    protected function setUp(): void
    {
        if (Config::get('APP_ENV') !== 'test' || Config::get('DB_HOST') !== 'db-test' || Config::get('DB_NAME') !== 'app_test') {
            throw new \LogicException('Migrator tests require the isolated db-test service');
        }
        $this->pdo = (new Connection())->pdo();
        $this->dropAllTables();
        $this->dir = sys_get_temp_dir() . '/migrations-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->fixture('20260101000000_create_first', 'migration_first');
        $this->fixture('20260101000001_create_second', 'migration_second');
        $this->migrator = new Migrator($this->pdo, $this->dir);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->dropAllTables();
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_constructor_and_status_do_not_write_and_missing_table_means_pending(): void
    {
        $this->pdo->exec('START TRANSACTION READ ONLY');
        try {
            $migrator = new Migrator($this->pdo, $this->dir);
            self::assertSame(['pending', 'pending'], array_column($migrator->status(), 'status'));
            self::assertSame(0, (int) ($this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations'") ?: self::fail('Database query failed'))->fetchColumn());
        } finally {
            $this->pdo->exec('ROLLBACK');
        }
    }

    public function test_migrate_is_repeatable_and_rollback_reverts_batches(): void
    {
        self::assertSame(['20260101000000_create_first', '20260101000001_create_second'], $this->migrator->migrate());
        self::assertSame([], $this->migrator->migrate());
        self::assertSame(['applied', 'applied'], array_column($this->migrator->status(), 'status'));
        $this->fixture('20260101000002_noop', null);
        self::assertSame(['20260101000002_noop'], $this->migrator->migrate());
        self::assertSame(['20260101000002_noop'], $this->migrator->rollback());
        self::assertSame(['20260101000001_create_second', '20260101000000_create_first'], $this->migrator->rollback());
        self::assertSame([], $this->migrator->rollback());
        self::assertSame(['pending', 'pending', 'pending'], array_column($this->migrator->status(), 'status'));
    }

    public function test_rollback_removes_each_successful_record_before_later_failure_and_can_resume(): void
    {
        $this->migrator->migrate();
        $this->fixture('20260101000000_create_first', 'migration_first', true);
        try {
            $this->migrator->rollback();
            self::fail('Expected failed down');
        } catch (RuntimeException $error) {
            self::assertSame('failed down', $error->getMessage());
        }
        self::assertSame(['20260101000000_create_first'], ($this->pdo->query('SELECT migration FROM migrations') ?: self::fail('Database query failed'))->fetchAll(PDO::FETCH_COLUMN));
        $this->assertLockFree();
        $this->fixture('20260101000000_create_first', 'migration_first');
        self::assertSame(['20260101000000_create_first'], $this->migrator->rollback());
    }

    public function test_failed_up_releases_lock_and_does_not_record_migration(): void
    {
        file_put_contents($this->dir . '/20260101000000_create_first.php', "<?php return ['up' => static function (PDO \$pdo): void { throw new RuntimeException('failed up'); }, 'down' => static fn(PDO \$pdo) => null];");
        try {
            $this->migrator->migrate();
            self::fail('Expected failed up');
        } catch (RuntimeException $error) {
            self::assertSame('failed up', $error->getMessage());
        }
        self::assertSame(0, (int) ($this->pdo->query('SELECT COUNT(*) FROM migrations') ?: self::fail('Database query failed'))->fetchColumn());
        $this->assertLockFree();
    }

    public function test_migrate_and_rollback_refuse_to_run_under_another_connections_lock(): void
    {
        $other = (new Connection())->pdo();
        $statement = $other->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$this->lockName()]);
        self::assertSame(1, (int) $statement->fetchColumn());
        try {
            foreach (['migrate', 'rollback'] as $method) {
                try {
                    $this->migrator->$method();
                    self::fail('Expected lock timeout');
                } catch (RuntimeException $error) {
                    self::assertSame('Could not acquire migration lock', $error->getMessage());
                }
            }
            self::assertSame(0, (int) ($this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations'") ?: self::fail('Database query failed'))->fetchColumn());
        } finally {
            $statement = $other->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$this->lockName()]);
        }
        self::assertCount(2, $this->migrator->migrate());
    }

    public function test_invalid_filename_and_non_callable_up_or_down_are_rejected_before_migrations_run(): void
    {
        foreach ([['bad.php', "['up' => static fn() => null, 'down' => static fn() => null]"],
            ['20260101000002_bad.php', "['up' => static fn() => null]"],
            ['20260101000002_bad.php', "['up' => null, 'down' => static fn() => null]"],
            ['20260101000003_bad.php', "'wrong type'"]] as [$filename, $definition]) {
            file_put_contents($this->dir . '/' . $filename, '<?php return ' . $definition . ';');
            try {
                $this->migrator->migrate();
                self::fail('Expected invalid migration');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Invalid migration', $error->getMessage());
            } finally {
                unlink($this->dir . '/' . $filename);
            }
            self::assertSame(0, (int) ($this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migration_first'") ?: self::fail('Database query failed'))->fetchColumn());
            $this->assertLockFree();
        }
    }

    private function dropAllTables(): void
    {
        $tables = ($this->pdo->query('SHOW TABLES') ?: self::fail('Database query failed'))->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $this->pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function fixture(string $name, ?string $table, bool $failDown = false): void
    {
        $up = $table === null ? '' : '$pdo->exec("CREATE TABLE ' . $table . ' (id INT PRIMARY KEY)");';
        $down = $failDown ? 'throw new RuntimeException("failed down");' : ($table === null ? '' : '$pdo->exec("DROP TABLE ' . $table . '");');
        file_put_contents($this->dir . '/' . $name . '.php', '<?php return ["up" => static function (PDO $pdo): void {' . $up . '}, "down" => static function (PDO $pdo): void {' . $down . '}];');
    }

    private function lockName(): string
    {
        return 'migrations:' . substr(hash('sha256', Config::get('DB_NAME')), 0, 48);
    }

    private function assertLockFree(): void
    {
        $statement = $this->pdo->prepare('SELECT IS_FREE_LOCK(?)');
        $statement->execute([$this->lockName()]);
        self::assertSame(1, (int) $statement->fetchColumn());
    }
}
