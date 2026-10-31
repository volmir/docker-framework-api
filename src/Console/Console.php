<?php
declare(strict_types=1);

namespace App\Console;

final class Console
{
    /** @var array<string, array{name:string, description?:string, execute:callable}> */
    private array $commands = [];

    public function __construct(string $commandsDir)
    {
        foreach (glob(rtrim($commandsDir, '/') . '/*.php') ?: [] as $file) {
            $command = require $file;
            if (!is_array($command) || !isset($command['name'], $command['execute']) || !is_callable($command['execute'])) {
                throw new \RuntimeException("Invalid command file: {$file}");
            }
            $this->commands[$command['name']] = $command;
        }
    }

    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        if (in_array($name, ['list', '--help', '-h'], true)) {
            $this->listCommands();
            return 0;
        }

        if (!isset($this->commands[$name])) {
            fwrite(STDERR, "Command not found: {$name}\n");
            $this->listCommands();
            return 1;
        }

        try {
            return (int) (($this->commands[$name]['execute'])($args) ?? 0);
        } catch (\Throwable $error) {
            fwrite(STDERR, "\033[31mError: {$error->getMessage()}\033[0m\n");
            return 1;
        }
    }

    private function listCommands(): void
    {
        echo "Available commands:\n";
        foreach ($this->commands as $command) {
            printf("  %-15s %s\n", $command['name'], $command['description'] ?? '');
        }
    }
}
