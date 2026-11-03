<?php
declare(strict_types=1);
use App\{Console\Generator, Database\Connection};
/** @var array{name:string, description:string, execute:callable(list<string>, Connection):int} $command */
$command = [
    'name' => 'make:domain', 'description' => 'Generate a domain and its constructor test; use a meaningful plural name',
    'execute' => static function (array $args, Connection $connection): int {
        /** @var list<string> $args */
        if (count($args) !== 1 || !preg_match('/\A[A-Z][A-Za-z0-9]*\z/', $args[0])) { throw new RuntimeException('Usage: make:domain <PluralStudlyCaseName>'); }
        $name = $args[0]; $root = dirname(__DIR__);
        if (preg_match('/(?:Service|Repository|Manager)\z/i', $name)) { throw new RuntimeException('Name the domain for its meaning: use a plural noun without Service/Repository/Manager'); }
        if (PhpToken::tokenize('<?php ' . $name)[1]->id !== T_STRING || in_array(strtolower($name), ['self', 'parent', 'int', 'float', 'bool', 'string', 'null', 'true', 'false', 'void', 'iterable', 'object', 'mixed', 'never'], true)) { throw new RuntimeException('Invalid PHP class name: ' . $name); }
        if (in_array(strtolower($name), ['http', 'middleware', 'controller', 'database', 'console', 'log', 'tests', 'unit', 'feature', 'architecture', 'fixtures'], true) || class_exists('App\\' . $name . '\\' . $name)) { throw new RuntimeException('Domain name is reserved or its class already exists: ' . $name); }
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace App\__NAME__;
final class __NAME__ {
    public function __construct(\App\Database\Connection $_connection) {}
}
PHP;
        $test = <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Tests\__NAME__;
final class __NAME__Test extends \App\Tests\DatabaseTestCase {
    public function test_constructor(): void {
        self::assertInstanceOf(\App\__NAME__\__NAME__::class, new \App\__NAME__\__NAME__($this->connection));
    }
}
PHP;
        $files = ['src/' . $name . '/' . $name . '.php' => $source, 'tests/' . $name . '/' . $name . 'Test.php' => $test];
        foreach (array_keys($files) as $path) { if (file_exists($root . '/' . $path) || is_link($root . '/' . $path)) { throw new RuntimeException('Domain file already exists: ' . $path); } }
        $written = []; $generator = new Generator($root);
        try { foreach ($files as $path => $content) { $written[] = $generator->write($path, str_replace('__NAME__', $name, $content) . PHP_EOL); } }
        catch (Throwable $error) { foreach ($written as $path) { @unlink($root . '/' . $path); } throw $error; }
        foreach ($written as $path) { echo $path . PHP_EOL; }
        echo 'Add the schema with: make migration name=create_' . Generator::snake($name) . '_table' . PHP_EOL; return 0;
    },
]; return $command;
