<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Console\Generator;
use App\Database\Connection;
use App\Http\{Middleware, Request, Response, Router};
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScaffoldingTest extends TestCase
{
    private string $directory;
    /** @var array<string, mixed> */
    private array $environment;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/scaffolding-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/commands', 0755, true);
        foreach (['make_controller', 'make_middleware', 'make_domain'] as $name) {
            copy(__DIR__ . '/../../commands/' . $name . '.php', $this->directory . '/commands/' . $name . '.php');
        }
        $this->environment = $_ENV;
        $_ENV = array_replace($_ENV, ['APP_ENV' => 'test', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
            'API_KEYS' => 'scaffolding:scaffolding-test-key']);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->directory,
            \FilesystemIterator::SKIP_DOTS,
        ), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public function test_printed_routes_create_the_generated_controller_and_all_actions_return_200(): void
    {
        $output = $this->generate('controller', ['GeneratedArticle', 'index', 'show', 'create', 'update', 'delete', 'publishNow']);
        $file = $this->directory . '/src/Controller/GeneratedArticleController.php';
        $this->lint($file);
        $this->assertProcessSucceeds([PHP_BINARY, dirname(__DIR__, 2) . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=256M', $file]);
        require $file;
        self::assertStringStartsWith("src/Controller/GeneratedArticleController.php\n", $output);
        self::assertStringContainsString('then run `make routes-check`', $output);
        self::assertStringContainsString("'GET /generated_articles/show'", $output);
        self::assertStringContainsString("'GET /generated_articles/publishNow'", $output);
        self::assertStringContainsString("'PATCH /generated_articles'", $output);
        self::assertStringContainsString("'DELETE /generated_articles'", $output);
        $lines = array_filter(explode("\n", $output), static fn(string $line): bool => str_starts_with($line, "'"));
        /** @var array<string, array{0:class-string, 1:string, 2:list<class-string<Middleware>>}> $routes */
        $routes = eval('use App\\Controller\\GeneratedArticleController; use App\\Middleware\\{ApiKeyAuth, RequireJson}; return [' . implode("\n", $lines) . '];');
        self::assertCount(6, $routes);
        $router = new Router($routes, new Connection());
        foreach ($routes as $key => [$class, $method, $layers]) {
            [$verb, $path] = explode(' ', $key, 2);
            self::assertSame(in_array($verb, ['POST', 'PATCH'], true)
                ? [\App\Middleware\ApiKeyAuth::class, \App\Middleware\RequireJson::class]
                : [\App\Middleware\ApiKeyAuth::class], $layers);
            self::assertSame(401, $router->handle(new Request($verb, $path))->status);
            $headers = ['X-Api-Key' => 'scaffolding-test-key'];
            if (in_array($verb, ['POST', 'PATCH'], true)) {
                self::assertSame(415, $router->handle(new Request($verb, $path, headers: $headers))->status);
                $headers['Content-Type'] = 'application/json';
            }
            $response = $router->handle(new Request($verb, $path, query: ['id' => '1'], headers: $headers));
            self::assertSame(200, $response->status);
            self::assertSame(['data' => []], json_decode($response->body, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function test_controller_defaults_to_index_and_name_variants_normalize_to_one_class(): void
    {
        $source = null;
        foreach (['blog_post', 'BlogPost', 'BlogPostController'] as $name) {
            $output = $this->generate('controller', [$name]);
            $file = $this->directory . '/src/Controller/BlogPostController.php';
            self::assertStringStartsWith('src/Controller/BlogPostController.php', $output);
            self::assertStringContainsString("'GET /blog_posts' => [BlogPostController::class, 'index', [ApiKeyAuth::class]],", $output);
            $content = file_get_contents($file);
            self::assertIsString($content);
            if ($source !== null) {
                self::assertSame($source, $content);
            }
            $source = $content;
            $this->reject('controller', [$name], 'already exists');
            self::assertSame($content, file_get_contents($file));
            unlink($file);
        }
    }

    public function test_controller_routes_are_inserted_with_qualified_names_and_pass_route_check(): void
    {
        $file = $this->routeFixture();
        chmod($file, 0640);
        $owner = fileowner($file);
        $group = filegroup($file);
        $this->generate('controller', ['RegisteredPost', 'index', 'show', 'create', '--routes']);
        $source = file_get_contents($file);
        self::assertIsString($source);
        self::assertStringContainsString("    'GET /registered_posts' => [\\App\\Controller\\RegisteredPostController::class, 'index', [\\App\\Middleware\\ApiKeyAuth::class]],\n    'GET /registered_posts/show'", $source);
        self::assertStringContainsString('[\\App\\Middleware\\ApiKeyAuth::class, \\App\\Middleware\\RequireJson::class]', $source);
        self::assertSame(1, substr_count($source, '    // make:controller: new routes are inserted above this line'));
        clearstatcache();
        self::assertSame(0640, fileperms($file) & 07777);
        self::assertSame($owner, fileowner($file));
        self::assertSame($group, filegroup($file));
        require $this->directory . '/src/Controller/RegisteredPostController.php';
        copy(__DIR__ . '/../../commands/routes.php', $this->directory . '/commands/routes.php');
        file_put_contents($this->directory . '/src/middleware.php', '<?php return [];');
        $command = require $this->directory . '/commands/routes.php';
        ob_start();
        try {
            self::assertSame(0, $command['execute'](['--check'], new Connection()));
        } finally {
            ob_end_clean();
        }
        self::assertSame(['RegisteredPostController.php'], array_map('basename', glob($this->directory . '/src/Controller/*') ?: []));
        self::assertSame(['Controller', 'middleware.php', 'routes.php'], array_map('basename', glob($this->directory . '/src/*') ?: []));
    }

    public function test_controller_routes_skip_duplicates_and_can_be_run_again_without_overwriting(): void
    {
        $file = $this->routeFixture("    'GET /repeat_posts' => [\\App\\Controller\\HealthController::class, 'index'],\n");
        $output = $this->generate('controller', ['RepeatPost', 'index', 'show', '--routes']);
        self::assertStringContainsString('Skipping existing route: GET /repeat_posts', $output);
        $source = file_get_contents($file);
        self::assertIsString($source);
        self::assertSame(1, substr_count($source, "'GET /repeat_posts'"));
        self::assertStringContainsString("'GET /repeat_posts/show'", $source);
        $controller = file_get_contents($this->directory . '/src/Controller/RepeatPostController.php');
        require $this->directory . '/src/Controller/RepeatPostController.php';
        $output = $this->generate('controller', ['RepeatPost', 'index', 'show', '--routes']);
        self::assertSame(2, substr_count($output, 'Skipping existing route:'));
        self::assertSame($source, file_get_contents($file));
        self::assertSame($controller, file_get_contents($this->directory . '/src/Controller/RepeatPostController.php'));
        $this->reject('controller', ['RepeatPost', 'delete', '--routes'], 'missing a requested action');
        self::assertSame($source, file_get_contents($file));
        self::assertSame($controller, file_get_contents($this->directory . '/src/Controller/RepeatPostController.php'));
    }

    public function test_controller_routes_require_the_exact_marker_and_print_manual_routes_on_refusal(): void
    {
        $file = $this->routeFixture();
        $source = str_replace('    // make:controller:', '   // make:controller:', file_get_contents($file) ?: '');
        file_put_contents($file, $source);
        $command = require $this->directory . '/commands/make_controller.php';
        ob_start();
        try {
            try {
                $command['execute'](['MissingMarker', '--routes'], new Connection());
                self::fail('Expected refusal');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('marker', $error->getMessage());
            }
            $output = ob_get_contents();
            self::assertIsString($output);
        } finally {
            ob_end_clean();
        }
        self::assertStringContainsString("'GET /missing_markers'", $output);
        self::assertSame($source, file_get_contents($file));
        self::assertFileDoesNotExist($this->directory . '/src/Controller/MissingMarkerController.php');
    }

    public function test_controller_routes_roll_back_a_lint_failure_and_a_failed_table_reload(): void
    {
        foreach ([
            'LintRollback' => "<?php\n    // make:controller: new routes are inserted above this line\nreturn [];\n",
            'LoadRollback' => "<?php\n\$routes = [\n    // make:controller: new routes are inserted above this line\n];\nif (\$routes !== []) { throw new RuntimeException('Reload refused'); }\nreturn \$routes;\n",
            'MarkerInComment' => "<?php\n/*\n    // make:controller: new routes are inserted above this line\n*/\nreturn [];\n",
        ] as $name => $source) {
            $file = $this->routeFixture();
            file_put_contents($file, $source);
            chmod($file, 0640);
            $owner = fileowner($file);
            $group = filegroup($file);
            $this->reject('controller', [$name, '--routes'], match ($name) {
                'LintRollback' => 'lint failed',
                'LoadRollback' => 'Reload refused',
                default => 'did not load inserted routes',
            });
            clearstatcache();
            self::assertSame($source, file_get_contents($file));
            self::assertSame(0640, fileperms($file) & 07777);
            self::assertSame($owner, fileowner($file));
            self::assertSame($group, filegroup($file));
            self::assertFileDoesNotExist($this->directory . '/src/Controller/' . $name . 'Controller.php');
            self::assertSame(['routes.php'], array_map('basename', glob($this->directory . '/src/*') ?: []));
            self::assertSame([], glob($this->directory . '/src/.routes-*'));
        }
    }

    public function test_controller_routes_refuse_production_and_roll_back_when_controller_cannot_be_written(): void
    {
        $file = $this->routeFixture();
        $source = file_get_contents($file);
        $_ENV['APP_ENV'] = 'production';
        $this->reject('controller', ['ProductionRoute', '--routes'], 'disabled in production');
        $_ENV['APP_ENV'] = 'test';
        self::assertSame($source, file_get_contents($file));
        self::assertDirectoryDoesNotExist($this->directory . '/src/Controller');
        mkdir($this->directory . '/src/Controller', 0555);
        try {
            $this->reject('controller', ['UnwritableRoute', '--routes'], 'not writable');
        } finally {
            chmod($this->directory . '/src/Controller', 0755);
        }
        self::assertSame($source, file_get_contents($file));
        self::assertSame([], glob($this->directory . '/src/Controller/*'));
        self::assertSame([], glob($this->directory . '/src/.routes-*'));
    }

    private function routeFixture(string $entries = ''): string
    {
        if (!is_dir($this->directory . '/src')) {
            mkdir($this->directory . '/src', 0755);
        }
        $file = $this->directory . '/src/routes.php';
        file_put_contents($file, "<?php\nreturn [\n" . $entries . "    // make:controller: new routes are inserted above this line\n];\n");
        return $file;
    }

    public function test_generated_middleware_implements_the_interface_and_passes_the_same_request_on(): void
    {
        $output = $this->generate('middleware', ['GeneratedPassThrough']);
        $file = $this->directory . '/src/Middleware/GeneratedPassThrough.php';
        $this->lint($file);
        require $file;
        self::assertStringContainsString('src/middleware.php (global)', $output);
        self::assertStringContainsString('third route element in src/routes.php', $output);
        $class = 'App\\Middleware\\' . basename($file, '.php');
        self::assertTrue(class_exists($class));
        $middleware = new $class();
        self::assertInstanceOf(Middleware::class, $middleware);
        $request = new Request('GET', '/example');
        $expected = Response::json(['passed' => true]);
        $called = 0;
        $response = $middleware->handle($request, static function (Request $next) use ($request, $expected, &$called): Response {
            self::assertSame($request, $next);
            ++$called;
            return $expected;
        });
        self::assertSame($expected, $response);
        self::assertSame(1, $called);
    }

    public function test_invalid_names_arguments_actions_and_existing_classes_leave_no_generated_files(): void
    {
        foreach ([[], [''], ['../Post'], ['Bad/Name'], ['bad-name'], ['1Post'], ['Controller'], ['Post', '__construct'],
            ['Post', 'bad-action'], ['Post', 'Show'], ['Post', 'index', 'index'], ['Post', 'getFoo', 'getfoo'], ['User']] as $args) {
            $this->reject('controller', $args);
        }
        foreach ([[], [''], ['snake_case'], ['../Throttle'], ['1Throttle'], ['Throttle', 'extra'], ['ApiKeyAuth'], ['Class'], ['Readonly'], ['String'], ['Mixed']] as $args) {
            $this->reject('middleware', $args);
        }
        self::assertDirectoryDoesNotExist($this->directory . '/src');
    }

    public function test_existing_files_and_symlinks_are_never_overwritten(): void
    {
        foreach (['controller' => ['Existing', 'src/Controller/ExistingController.php'],
            'middleware' => ['ExistingLayer', 'src/Middleware/ExistingLayer.php']] as $kind => [$name, $relative]) {
            $file = $this->directory . '/' . $relative;
            mkdir(dirname($file), 0755, true);
            file_put_contents($file, 'keep this file');
            $this->reject($kind, [$name], 'already exists');
            self::assertSame('keep this file', file_get_contents($file));
            unlink($file);
            symlink($this->directory . '/absent-target', $file);
            $this->reject($kind, [$name], 'already exists');
            self::assertTrue(is_link($file));
            self::assertFileDoesNotExist($this->directory . '/absent-target');
        }
    }

    public function test_production_and_read_only_destinations_fail_with_a_dev_hint(): void
    {
        $_ENV['APP_ENV'] = 'production';
        foreach (['controller' => 'PrivatePost', 'middleware' => 'PrivateLayer'] as $kind => $name) {
            $this->reject($kind, [$name], 'disabled in production');
        }
        self::assertDirectoryDoesNotExist($this->directory . '/src');
        $_ENV['APP_ENV'] = 'test';
        mkdir($this->directory . '/src', 0555);
        try {
            foreach (['controller' => 'PrivatePost', 'middleware' => 'PrivateLayer'] as $kind => $name) {
                $this->reject($kind, [$name], 'not writable');
            }
        } finally {
            chmod($this->directory . '/src', 0755);
        }
        self::assertSame([], glob($this->directory . '/src/*'));
    }

    public function test_generator_creates_0755_directories_and_0644_files_even_with_a_strict_umask(): void
    {
        $mask = umask(0077);
        try {
            $relative = (new Generator($this->directory))->write('src/Controller/Mode.php', '<?php');
        } finally {
            umask($mask);
        }
        self::assertSame('src/Controller/Mode.php', $relative);
        foreach (['src', 'src/Controller'] as $dir) {
            self::assertSame(0755, fileperms($this->directory . '/' . $dir) & 0777);
        }
        self::assertSame(0644, fileperms($this->directory . '/' . $relative) & 0777);
        self::assertSame('BlogPost', Generator::studly('blog-post'));
        self::assertSame('BlogPost', Generator::studly('blog_post'));
        self::assertSame('blog_post', Generator::snake('BlogPost'));
        self::assertSame('http_post', Generator::snake('HTTPPost'));
    }

    public function test_generators_leave_route_and_global_middleware_registration_unchanged(): void
    {
        mkdir($this->directory . '/src', 0755);
        foreach (['routes', 'middleware'] as $name) {
            file_put_contents($this->directory . '/src/' . $name . '.php', '<?php return [];');
        }
        $this->generate('controller', ['UnregisteredPost']);
        $this->generate('middleware', ['UnregisteredLayer']);
        $this->generate('domain', ['UnregisteredPosts']);
        foreach (['routes', 'middleware'] as $name) {
            self::assertSame('<?php return [];', file_get_contents($this->directory . '/src/' . $name . '.php'));
        }
    }

    public function test_middleware_names_can_match_http_types_without_import_collisions(): void
    {
        foreach (['Request', 'Response', 'Middleware'] as $name) {
            $this->generate('middleware', [$name]);
            $file = $this->directory . '/src/Middleware/' . $name . '.php';
            $this->lint($file);
            require $file;
            $class = 'App\\Middleware\\' . basename($file, '.php');
            self::assertTrue(class_exists($class));
            self::assertInstanceOf(Middleware::class, new $class());
        }
    }

    public function test_domain_generates_a_linted_analysed_class_and_a_runnable_constructor_test(): void
    {
        $output = $this->generate('domain', ['SamplePosts']);
        $source = $this->directory . '/src/SamplePosts/SamplePosts.php';
        $test = $this->directory . '/tests/SamplePosts/SamplePostsTest.php';
        self::assertStringStartsWith("src/SamplePosts/SamplePosts.php\ntests/SamplePosts/SamplePostsTest.php\n", $output);
        self::assertStringContainsString('make migration name=create_sample_posts_table', $output);
        foreach ([$source, $test] as $file) {
            $this->lint($file);
            self::assertSame(0644, fileperms($file) & 0777);
        }
        $this->assertProcessSucceeds([PHP_BINARY, dirname(__DIR__, 2) . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=256M', $source, $test]);
        $bootstrap = $this->directory . '/bootstrap.php';
        file_put_contents($bootstrap, '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; require ' . var_export($source, true) . ';');
        mkdir($this->directory . '/tests/Architecture', 0755);
        copy(__DIR__ . '/../Architecture/DependencyRulesTest.php', $this->directory . '/tests/Architecture/DependencyRulesTest.php');
        foreach (['bin', 'public'] as $path) {
            mkdir($this->directory . '/' . $path, 0755);
        }
        copy(dirname(__DIR__, 2) . '/composer.json', $this->directory . '/composer.json');
        $result = $this->assertProcessSucceeds([PHP_BINARY, dirname(__DIR__, 2) . '/vendor/bin/phpunit', '--bootstrap', $bootstrap, $this->directory . '/tests']);
        self::assertStringContainsString('OK (3 tests, 3 assertions)', $result);
        $before = [file_get_contents($source), file_get_contents($test)];
        $this->reject('domain', ['SamplePosts'], 'already exists');
        self::assertSame($before, [file_get_contents($source), file_get_contents($test)]);
    }

    public function test_domain_rejects_invalid_reserved_and_layer_names_before_writing(): void
    {
        foreach ([[], [''], ['snake_case'], ['../Posts'], ['1Posts'], ['Posts', 'extra'], ['Class'], ['String'], ['Http'], ['Tests'], ['Fixtures'], ['Unit'], ['Feature'], ['Architecture'], ['Users']] as $args) {
            $this->reject('domain', $args);
        }
        foreach (['PostsService', 'PostsRepository', 'PostsManager', 'Postsmanager'] as $name) {
            $this->reject('domain', [$name], 'Name the domain for its meaning');
        }
        self::assertDirectoryDoesNotExist($this->directory . '/src');
        self::assertDirectoryDoesNotExist($this->directory . '/tests');
        $_ENV['APP_ENV'] = 'production';
        $this->reject('domain', ['PrivatePosts'], 'disabled in production');
        self::assertDirectoryDoesNotExist($this->directory . '/src');
        self::assertDirectoryDoesNotExist($this->directory . '/tests');
    }

    public function test_domain_checks_both_files_before_creating_either_of_them(): void
    {
        foreach (['src/ExistingPosts/ExistingPosts.php', 'tests/ExistingPosts/ExistingPostsTest.php'] as $relative) {
            $file = $this->directory . '/' . $relative;
            mkdir(dirname($file), 0755, true);
            file_put_contents($file, 'existing content');
            $this->reject('domain', ['ExistingPosts'], 'already exists');
            self::assertSame('existing content', file_get_contents($file));
            $other = str_starts_with($relative, 'src/') ? 'tests/ExistingPosts/ExistingPostsTest.php' : 'src/ExistingPosts/ExistingPosts.php';
            self::assertFileDoesNotExist($this->directory . '/' . $other);
            unlink($file);
        }
    }

    public function test_domain_removes_its_new_source_if_writing_the_test_fails(): void
    {
        mkdir($this->directory . '/tests', 0555);
        try {
            $this->reject('domain', ['ReadOnlyPosts'], 'not writable');
        } finally {
            chmod($this->directory . '/tests', 0755);
        }
        self::assertFileDoesNotExist($this->directory . '/src/ReadOnlyPosts/ReadOnlyPosts.php');
        self::assertFileDoesNotExist($this->directory . '/tests/ReadOnlyPosts/ReadOnlyPostsTest.php');
    }

    /** @param list<string> $args */
    private function generate(string $kind, array $args): string
    {
        $command = require $this->directory . '/commands/make_' . $kind . '.php';
        ob_start();
        try {
            self::assertSame(0, $command['execute']($args, new Connection()));
            $output = ob_get_contents();
            self::assertIsString($output);
            return $output;
        } finally {
            ob_end_clean();
        }
    }

    /** @param list<string> $args */
    private function reject(string $kind, array $args, ?string $message = null): void
    {
        try {
            $this->generate($kind, $args);
            self::fail('Expected generation to fail');
        } catch (RuntimeException $error) {
            if ($message !== null) {
                self::assertStringContainsString($message, $error->getMessage());
            }
            if ($message === 'not writable' || $message === 'disabled in production') {
                self::assertStringContainsString('dev environment', $error->getMessage());
            }
        }
    }

    private function lint(string $file): void
    {
        $this->assertProcessSucceeds([PHP_BINARY, '-l', $file]);
    }

    /** @param list<string> $command */
    private function assertProcessSucceeds(array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        self::assertIsString($output);
        self::assertIsString($error);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        return $output . $error;
    }
}
