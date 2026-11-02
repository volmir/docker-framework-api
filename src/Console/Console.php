<?php
declare(strict_types=1);

namespace App\Console;

use App\Database\Connection;

final class Console
{
    /** @var array<string, array{name:string, description:string, execute:callable(list<string>, Connection): ?int}> */
    private array $commands = [];

    public function __construct(string $commandsDir, private Connection $connection)
    {
        foreach (glob(rtrim($commandsDir, '/') . '/*.php') ?: [] as $file) {
            $command = require $file;
            if (!is_array($command) || !is_string($command['name'] ?? null)
                || !is_string($command['description'] ?? '') || !is_callable($command['execute'] ?? null)) {
                throw new \RuntimeException("Invalid command file: {$file}");
            }
            $this->commands[$command['name']] = [
                'name' => $command['name'], 'description' => $command['description'] ?? '', 'execute' => $command['execute'],
            ];
        }
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        if (in_array($name, ['list', 'help', '--help', '-h'], true)) {
            $this->listCommands();
            return 0;
        }

        if (!isset($this->commands[$name])) {
            fwrite(STDERR, "Command not found: {$name}\n");
            $this->listCommands();
            return 1;
        }

        try {
            return (int) (($this->commands[$name]['execute'])($args, $this->connection) ?? 0);
        } catch (\Throwable $error) {
            \App\Middleware\ErrorHandler::response($error);
            fwrite(STDERR, self::color('Error: ' . \App\Log\Redactor::clean($error->getMessage()), 31) . PHP_EOL);
            return 1;
        }
    }

    public static function color(string $message, int $color): string
    {
        return stream_isatty(STDOUT) ? "\033[{$color}m{$message}\033[0m" : $message;
    }

    private function listCommands(): void
    {
        echo "Available commands:\n";
        foreach ($this->commands as $command) {
            printf("  %-15s %s\n", $command['name'], $command['description']);
        }
    }
}
