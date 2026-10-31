<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private PDO $pdo;
    private string $dir;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->dir = sys_get_temp_dir() . '/migrations-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/20260101000000_create_users.php', <<<'PHP'
<?php
return [
    'up' => static function (PDO $pdo): void { $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)'); },
    'down' => static function (PDO $pdo): void { $pdo->exec('DROP TABLE users'); },
];
PHP);
        file_put_contents($this->dir . '/20260101000001_create_posts.php', <<<'PHP'
<?php
return [
    'up' => static function (PDO $pdo): void { $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL)'); },
    'down' => static function (PDO $pdo): void { $pdo->exec('DROP TABLE posts'); },
];
PHP);
        $this->migrator = new Migrator($this->pdo, $this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.php') ?: [] as $file) unlink($file);
        rmdir($this->dir);
    }

    public function test_migrate_applies_all_pending_migrations_and_then_returns_empty(): void
    {
        self::assertSame([
            '20260101000000_create_users',
            '20260101000001_create_posts',
        ], $this->migrator->migrate());
        self::assertSame([], $this->migrator->migrate());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'posts'")->fetchColumn());
    }

    public function test_rollback_reverts_the_latest_batch(): void
    {
        $this->migrator->migrate();

        self::assertSame([
            '20260101000001_create_posts',
            '20260101000000_create_users',
        ], $this->migrator->rollback());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('users', 'posts')")->fetchColumn());
    }

    public function test_status_reports_pending_and_applied_migrations(): void
    {
        $pending = $this->migrator->status();
        self::assertSame('pending', $pending[0]['status']);

        $this->migrator->migrate();

        $applied = $this->migrator->status();
        self::assertSame('applied', $applied[0]['status']);
        self::assertSame('applied', $applied[1]['status']);
    }
}
