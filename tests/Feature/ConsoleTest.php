<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Console\Console;
use App\Database\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConsoleTest extends TestCase
{
    private string $commandsDir;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection();
        $this->commandsDir = sys_get_temp_dir() . '/console-test-' . bin2hex(random_bytes(6));
        mkdir($this->commandsDir);
        file_put_contents($this->commandsDir . '/first.php', <<<'PHP'
<?php
declare(strict_types=1);
return ['name' => 'first', 'description' => 'First test command', 'execute' => static function (array $args, App\Database\Connection $connection): int {
    file_put_contents(__DIR__ . '/arguments.json', json_encode(['args' => $args, 'connection_id' => spl_object_id($connection)]));
    return 7;
}];
PHP);
        file_put_contents($this->commandsDir . '/second.php', <<<'PHP'
<?php
declare(strict_types=1);
return ['name' => 'second', 'description' => 'Second test command', 'execute' => static fn(array $args, App\Database\Connection $connection): int => 0];
PHP);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->commandsDir . '/*') ?: [] as $file) unlink($file);
        rmdir($this->commandsDir);
    }

    public function test_existing_command_runs_and_returns_its_code(): void
    {
        self::assertSame(7, (new Console($this->commandsDir, $this->connection))->run(['console', 'first', 'one', 'two']));
        $arguments = file_get_contents($this->commandsDir . '/arguments.json');
        self::assertIsString($arguments);
        self::assertSame(
            ['args' => ['one', 'two'], 'connection_id' => spl_object_id($this->connection)],
            json_decode($arguments, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_missing_command_returns_one(): void
    {
        self::assertSame(1, (new Console($this->commandsDir, $this->connection))->run(['console', 'missing']));
    }

    public function test_no_command_prints_the_list(): void
    {
        ob_start();
        $code = (new Console($this->commandsDir, $this->connection))->run(['console']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame(0, $code);
        self::assertStringContainsString('first', $output);
        self::assertStringContainsString('second', $output);
    }

    public function test_list_and_help_aliases_print_commands(): void
    {
        $console = new Console($this->commandsDir, $this->connection);
        foreach (['list', 'help', '--help', '-h'] as $name) {
            ob_start();
            try {
                $code = $console->run(['console', $name]);
                $output = ob_get_contents();
                self::assertIsString($output);
            } finally {
                ob_end_clean();
            }

            self::assertSame(0, $code);
            self::assertStringContainsString('first', $output);
            self::assertStringContainsString('second', $output);
        }
    }

    public function test_real_help_and_key_generation_work_without_a_database(): void
    {
        $environment = $_ENV;
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_PORT'] = '1';
        try {
            $console = new Console(__DIR__ . '/../../commands', new Connection());
            foreach (['list', 'help', '--help', '-h'] as $name) {
                ob_start();
                try {
                    self::assertSame(0, $console->run(['console', $name]));
                    $output = ob_get_contents();
                    self::assertIsString($output);
                } finally {
                    ob_end_clean();
                }
                self::assertStringContainsString('migrate', $output);
                self::assertStringContainsString('apikey:generate', $output);
                self::assertStringNotContainsString("\033[", $output);
            }
            ob_start();
            try {
                self::assertSame(0, $console->run(['console', 'apikey:generate', 'mobile']));
            } finally {
                ob_end_clean();
            }
        } finally {
            $_ENV = $environment;
        }
    }

    public function test_invalid_command_file_throws(): void
    {
        file_put_contents($this->commandsDir . '/broken.php', "<?php return ['execute' => static fn() => 0];");

        $this->expectException(RuntimeException::class);
        new Console($this->commandsDir, $this->connection);
    }

    public function test_api_key_generator_prints_a_key_once_and_its_matching_hash(): void
    {
        $console = new Console(__DIR__ . '/../../commands', $this->connection);
        ob_start();
        try {
            $code = $console->run(['console', 'apikey:generate', 'mobile']);
            $output = ob_get_contents();
            self::assertIsString($output);
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $code);
        if (preg_match('/\AAPI key \(shown once\): ([a-f0-9]{64})\nmobile:([a-f0-9]{64})\n\z/', $output, $matches) !== 1) {
            self::fail('Invalid API key generator output');
        }
        self::assertSame(hash('sha256', $matches[1]), $matches[2]);
        self::assertSame(1, substr_count($output, $matches[1]));
    }

    public function test_api_key_generator_rejects_missing_invalid_or_extra_client_arguments(): void
    {
        $command = require __DIR__ . '/../../commands/apikey-generate.php';
        foreach ([[], ['bad:name'], ['bad name'], [str_repeat('a', 65)], ['_bad'], ['mobile', 'extra']] as $args) {
            try {
                $command['execute']($args, new Connection());
                self::fail('Expected invalid client');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Usage: apikey:generate <client>', $error->getMessage());
            }
        }
    }

    public function test_production_seed_and_rollback_require_force_before_opening_database(): void
    {
        $environment = $_ENV;
        $_ENV['APP_ENV'] = 'production';
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_PORT'] = '1';
        try {
            foreach (['seed', 'rollback'] as $name) {
                $command = require __DIR__ . '/../../commands/' . $name . '.php';
                try {
                    $command['execute']([], new Connection());
                    self::fail('Expected production guard');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('requires --force', $error->getMessage());
                }
                foreach ([['--force'], ['--force', $name === 'seed' ? 'users' : '1'],
                    [$name === 'seed' ? 'users' : '1', '--force']] as $args) {
                    try {
                        $command['execute']($args, new Connection());
                        self::fail('Expected unavailable database after passing guard');
                    } catch (\PDOException $error) {
                        self::assertStringContainsString('Connection refused', $error->getMessage());
                    }
                }
            }
        } finally {
            $_ENV = $environment;
        }
    }
}
