<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\Seeder;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeederTest extends TestCase
{
    private PDO $pdo;
    private string $dir;
    private Seeder $seeder;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE users (email TEXT NOT NULL, password_hash TEXT NOT NULL)');
        $this->dir = sys_get_temp_dir() . '/seeders-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/users.php', <<<'PHP'
<?php
return ['run' => static function (PDO $pdo): void {
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
    $stmt->execute(['alice@example.com', 'hash-a']);
}];
PHP);
        file_put_contents($this->dir . '/users_extra.php', <<<'PHP'
<?php
return ['run' => static function (PDO $pdo): void {
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
    $stmt->execute(['bob@example.com', 'hash-b']);
}];
PHP);
        $this->seeder = new Seeder($this->pdo, $this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.php') ?: [] as $file) unlink($file);
        rmdir($this->dir);
    }

    public function test_run_without_name_runs_all_seeders_in_filename_order(): void
    {
        self::assertSame(['users', 'users_extra'], $this->seeder->run());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_run_with_name_runs_only_that_seeder(): void
    {
        self::assertSame(['users'], $this->seeder->run('users'));
        self::assertSame(['alice@example.com'], $this->pdo->query('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_missing_seeder_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->seeder->run('missing');
    }
}
