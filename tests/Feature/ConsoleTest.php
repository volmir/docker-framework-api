<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Console\Console;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConsoleTest extends TestCase
{
    private string $commandsDir;

    protected function setUp(): void
    {
        $this->commandsDir = sys_get_temp_dir() . '/console-test-' . bin2hex(random_bytes(6));
        mkdir($this->commandsDir);
        file_put_contents($this->commandsDir . '/first.php', <<<'PHP'
<?php
return ['name' => 'first', 'description' => 'First test command', 'execute' => static function (array $args): int {
    $GLOBALS['console_test_args'] = $args;
    return 7;
}];
PHP);
        file_put_contents($this->commandsDir . '/second.php', <<<'PHP'
<?php
return ['name' => 'second', 'description' => 'Second test command', 'execute' => static fn(array $args): int => 0];
PHP);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->commandsDir . '/*') ?: [] as $file) unlink($file);
        rmdir($this->commandsDir);
        unset($GLOBALS['console_test_args']);
    }

    public function test_existing_command_runs_and_returns_its_code(): void
    {
        self::assertSame(7, (new Console($this->commandsDir))->run(['console', 'first', 'one', 'two']));
        self::assertSame(['one', 'two'], $GLOBALS['console_test_args']);
    }

    public function test_missing_command_returns_one(): void
    {
        self::assertSame(1, (new Console($this->commandsDir))->run(['console', 'missing']));
    }

    public function test_no_command_prints_the_list(): void
    {
        ob_start();
        $code = (new Console($this->commandsDir))->run(['console']);
        $output = ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('first', $output);
        self::assertStringContainsString('second', $output);
    }

    public function test_invalid_command_file_throws(): void
    {
        file_put_contents($this->commandsDir . '/broken.php', "<?php return ['execute' => static fn() => 0];");

        $this->expectException(RuntimeException::class);
        new Console($this->commandsDir);
    }
}
