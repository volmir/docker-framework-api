<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use PHPUnit\Framework\TestCase;

final class GeneratorsTest extends TestCase
{
    private string $directory;
    /** @var array<string, string> */
    private array $environment;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/generators-' . bin2hex(random_bytes(6));
        foreach (['commands', 'database/migrations', 'bin'] as $path) { mkdir($this->directory . '/' . $path, 0777, true); }
        foreach (['make_migration', 'make_command', 'migrate', 'status', 'rollback'] as $name) {
            copy(__DIR__ . '/../../commands/' . $name . '.php', $this->directory . '/commands/' . $name . '.php');
        }
        copy(__DIR__ . '/../../bin/console', $this->directory . '/bin/console');
        foreach (['src', 'vendor'] as $path) { symlink(dirname(__DIR__, 2) . '/' . $path, $this->directory . '/' . $path); }
        $this->environment = array_map(static fn(mixed $value): string => (string) $value, Config::all());
        $this->environment = array_replace($this->environment, ['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1']);
    }

    protected function tearDown(): void
    {
        foreach (['commands', 'database/migrations'] as $path) {
            chmod($this->directory . '/' . $path, 0755);
            foreach (glob($this->directory . '/' . $path . '/*') ?: [] as $file) { unlink($file); }
            rmdir($this->directory . '/' . $path);
        }
        foreach (['src', 'vendor', 'bin/console'] as $path) { unlink($this->directory . '/' . $path); }
        foreach (['bin', 'database', ''] as $path) { rmdir($this->directory . '/' . $path); }
    }

    public function test_generated_migration_is_utc_valid_and_applies_and_rolls_back_through_real_commands(): void
    {
        self::assertSame('test', Config::env());
        self::assertSame('db-test', Config::get('DB_HOST'));
        self::assertSame('app_test', Config::get('DB_NAME'));
        $before = gmdate('YmdHis');
        [$code, $output, $error] = $this->runConsole(['make:migration', 'create_posts_table'], ['TZ' => 'Pacific/Honolulu']);
        self::assertSame(0, $code, $error);
        self::assertMatchesRegularExpression('/\Adatabase\/migrations\/[0-9]{14}_create_posts_table\.php\n\z/', $output);
        $file = $this->directory . '/' . trim($output);
        $name = pathinfo($file, PATHINFO_FILENAME);
        self::assertGreaterThanOrEqual($before, substr($name, 0, 14));
        self::assertLessThanOrEqual(gmdate('YmdHis'), substr($name, 0, 14));
        self::assertSame(0644, fileperms($file) & 0777);
        $migration = require $file;
        self::assertIsCallable($migration['up']);
        self::assertIsCallable($migration['down']);
        self::assertStringContainsString("'up' => function (PDO \$pdo): void {", (string) file_get_contents($file));
        $pdo = (new Connection())->pdo();
        $migrator = new Migrator($pdo, $this->directory . '/database/migrations');
        $database = ['DB_HOST' => Config::get('DB_HOST'), 'DB_PORT' => Config::get('DB_PORT')];
        try {
            self::assertSame([['name' => $name, 'status' => 'pending']], $migrator->status());
            [$code, $output, $error] = $this->runConsole(['status'], $database);
            self::assertSame(0, $code, $error);
            self::assertStringContainsString($name . ' — pending', $output);
            [$code, $output, $error] = $this->runConsole(['migrate'], $database);
            self::assertSame(0, $code, $error);
            self::assertStringContainsString('Migrated: ' . $name, $output);
            self::assertSame([['name' => $name, 'status' => 'applied']], $migrator->status());
            [$code, $output, $error] = $this->runConsole(['rollback'], $database);
            self::assertSame(0, $code, $error);
            self::assertStringContainsString('Rolled back: ' . $name, $output);
            self::assertSame([['name' => $name, 'status' => 'pending']], $migrator->status());
        } finally {
            // Remove only this test's record if a command failed partway through.
            $delete = $pdo->prepare('DELETE FROM migrations WHERE migration = ?');
            $delete->execute([$name]);
        }
    }

    public function test_migration_timestamp_follows_the_latest_by_one_second_across_year_boundary(): void
    {
        file_put_contents($this->directory . '/database/migrations/20991231235959_future.php', '<?php return [];');
        foreach (['next_step' => '21000101000000', 'another_step' => '21000101000001'] as $name => $stamp) {
            [$code, $output, $error] = $this->runConsole(['make:migration', $name]);
            self::assertSame(0, $code, $error);
            self::assertSame('database/migrations/' . $stamp . '_' . $name . ".php\n", $output);
        }
        [$code, , $error] = $this->runConsole(['make:migration', 'future']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Migration already exists: future', $error);
    }

    public function test_generated_command_is_listed_runs_without_database_and_rejects_extra_arguments(): void
    {
        foreach (['report:users', 'action', 'report:users_daily'] as $name) {
            [$code, $output, $error] = $this->runConsole(['make:command', $name]);
            self::assertSame(0, $code, $error);
            $relative = 'commands/' . str_replace(':', '_', $name) . '.php';
            self::assertSame($relative . PHP_EOL, $output);
            self::assertSame(0644, fileperms($this->directory . '/' . $relative) & 0777);
            [$code, $output, $error] = $this->runConsole(['list']);
            self::assertSame(0, $code, $error);
            self::assertStringContainsString($name, $output);
            [$code, , $error] = $this->runConsole([$name]);
            self::assertSame(0, $code, $error);
            [$code, , $error] = $this->runConsole([$name, 'unexpected']);
            self::assertSame(1, $code);
            self::assertStringContainsString('Usage: ' . $name, $error);
        }
    }

    public function test_duplicates_registered_under_different_filenames_and_file_collisions_are_not_overwritten(): void
    {
        foreach ([['make:migration', 'create_posts_table', 'database/migrations/*_create_posts_table.php'],
            ['make:command', 'report:users', 'commands/report_users.php']] as [$command, $name, $pattern]) {
            [$code, , $error] = $this->runConsole([$command, $name]);
            self::assertSame(0, $code, $error);
            $files = glob($this->directory . '/' . $pattern) ?: [];
            self::assertCount(1, $files);
            $content = file_get_contents($files[0]);
            [$code, , $error] = $this->runConsole([$command, $name]);
            self::assertSame(1, $code);
            self::assertStringContainsString('already exists', $error);
            self::assertSame($content, file_get_contents($files[0]));
        }
        foreach (['migrate', 'make:migration', 'list', 'help'] as $name) {
            [$code, , $error] = $this->runConsole(['make:command', $name]);
            self::assertSame(1, $code);
            self::assertStringContainsString('Command already exists: ' . $name, $error);
        }
        $file = $this->directory . '/commands/collision.php';
        $content = "<?php return ['name' => 'different', 'execute' => static fn() => 0];";
        file_put_contents($file, $content);
        [$code, , $error] = $this->runConsole(['make:command', 'collision']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot create command', $error);
        self::assertSame($content, file_get_contents($file));
    }

    public function test_invalid_names_and_argument_counts_are_rejected_without_creating_files(): void
    {
        $names = [
            'make:migration' => ['Create_posts', '1posts', 'create-posts', '../posts', 'posts.php', "posts\n"],
            'make:command' => ['Report:users', 'report:', ':users', 'report:1users', 'two:colon:names', 'plain_name', '../command'],
        ];
        foreach ($names as $command => $invalid) {
            foreach ([[], ['valid', 'extra'], ...array_map(static fn(string $name): array => [$name], $invalid)] as $args) {
                [$code, , $error] = $this->runConsole([$command, ...$args]);
                self::assertSame(1, $code);
                self::assertStringContainsString('Usage: ' . $command, $error);
                self::assertStringContainsString($command === 'make:migration' ? 'create_posts_table' : 'report:users', $error);
            }
        }
        self::assertSame([], glob($this->directory . '/database/migrations/*.php'));
        self::assertCount(5, glob($this->directory . '/commands/*.php') ?: []);
    }

    public function test_migration_name_mask_and_migrator_agree_on_repeated_and_trailing_underscores(): void
    {
        [$code, $output, $error] = $this->runConsole(['make:migration', 'create__posts_']);
        self::assertSame(0, $code, $error);
        $file = $this->directory . '/' . trim($output);
        self::assertSame('test', Config::env());
        self::assertSame('db-test', Config::get('DB_HOST'));
        self::assertSame('app_test', Config::get('DB_NAME'));
        $migrator = new Migrator((new Connection())->pdo(), $this->directory . '/database/migrations');
        self::assertSame([['name' => pathinfo($file, PATHINFO_FILENAME), 'status' => 'pending']], $migrator->status());
    }

    public function test_production_and_non_writable_directories_have_actionable_errors(): void
    {
        $production = ['APP_ENV' => 'production', 'APP_DEBUG' => '0', 'API_KEYS' => 'test:generator-test',
            'DB_PASSWORD' => 'private-test-password', 'DB_ROOT_PASSWORD' => 'private-root-password', 'CORS_ALLOW_ORIGIN' => 'https://example.com'];
        foreach ([['make:migration', 'create_posts_table', 'database/migrations', 'make migration name='],
            ['make:command', 'report:users', 'commands', 'make command name=']] as [$command, $name, $path, $hint]) {
            [$code, , $error] = $this->runConsole([$command, $name], $production);
            self::assertSame(1, $code);
            self::assertStringContainsString('disabled in production', $error);
            self::assertStringContainsString($hint, $error);
            chmod($this->directory . '/' . $path, 0555);
            try {
                [$code, , $error] = $this->runConsole([$command, $name]);
                self::assertSame(1, $code);
                self::assertStringContainsString('not writable', $error);
                self::assertStringContainsString($hint, $error);
                self::assertStringContainsString('dev environment', $error);
            } finally {
                chmod($this->directory . '/' . $path, 0755);
            }
        }
        self::assertSame([], glob($this->directory . '/database/migrations/*.php'));
        self::assertCount(5, glob($this->directory . '/commands/*.php') ?: []);
    }

    /** @param list<string> $args
     * @param array<string, string> $environment
     * @return array{int, string, string} */
    private function runConsole(array $args, array $environment = []): array
    {
        $environment = array_replace($this->environment, $environment);
        // proc_open drops empty values from env_vars; env preserves NAME= for the child.
        $empty = array_map(static fn(string $key): string => $key . '=', array_keys(array_filter($environment, static fn(string $value): bool => $value === '')));
        $process = proc_open(['env', ...$empty, PHP_BINARY, $this->directory . '/bin/console', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory, $environment);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }
}
