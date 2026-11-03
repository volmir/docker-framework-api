<?php
declare(strict_types=1);
use App\Console\Generator;
use App\Database\Connection;
/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'make:command',
    'description' => 'Generate a console command in development',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        if (count($args) !== 1 || !preg_match('/\A[a-z][a-z0-9]*(?::[a-z][a-z0-9_]*)?\z/', $args[0])) { throw new RuntimeException('Usage: make:command <name>, e.g. report:users'); }
        $name = $args[0];
        if (in_array($name, ['list', 'help'], true)) { throw new RuntimeException('Command already exists: ' . $name); }
        foreach (glob(__DIR__ . '/*.php') ?: [] as $file) { if ((require $file)['name'] === $name) { throw new RuntimeException('Command already exists: ' . $name); } }
        $content = <<<'PHP'
<?php
declare(strict_types=1);
use App\Database\Connection;
/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => '__NAME__',
    'description' => 'Describe what __NAME__ does',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        if ($args !== []) { fwrite(STDERR, "Usage: __NAME__\n"); return 1; }
        // $pdo = $connection->pdo(); // Open the connection only when SQL is needed.
        return 0;
    },
];
return $command;
PHP;
        echo (new Generator(dirname(__DIR__)))->write('commands/' . str_replace(':', '_', $name) . '.php', str_replace('__NAME__', $name, $content) . PHP_EOL) . PHP_EOL; return 0;
    },
];
return $command;
