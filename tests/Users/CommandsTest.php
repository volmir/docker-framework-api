<?php

declare(strict_types=1);

namespace App\Tests\Users;

use App\Config;
use App\Tests\DatabaseTestCase;

final class CommandsTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $emails = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        // CLI children commit on separate connections; remove only this test's users.
        $delete = $this->pdo->prepare('DELETE FROM users WHERE email = ?');
        foreach ($this->emails as $email) {
            $delete->execute([$email]);
        }
    }

    public function test_create_reads_password_from_stdin_and_duplicate_returns_one_without_disclosure(): void
    {
        $email = $this->email();
        $password = 'cli-password-' . bin2hex(random_bytes(8));
        [$code, $output, $error] = $this->console(['users:create', $email], $password . "\r\n");
        self::assertSame(0, $code, $error);
        $user = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['id', 'email', 'created_at'], array_keys($user));
        self::assertSame($email, $user['email']);
        self::assertStringNotContainsString($password, $output . $error);
        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE email = ?');
        $statement->execute([$email]);
        $hash = $statement->fetchColumn();
        self::assertIsString($hash);
        self::assertTrue(password_verify($password, $hash));
        [$code, $output, $error] = $this->console(['users:create', $email], $password . "\n");
        self::assertSame(1, $code);
        self::assertSame('', $output);
        self::assertStringContainsString('Email is already taken', $error);
        self::assertStringNotContainsString($password, $error);
    }

    public function test_create_rejects_bad_email_password_missing_input_and_password_arguments(): void
    {
        $email = $this->email();
        foreach ([[['users:create'], ''], [['users:create', $email, 'never-print-this-secret'], ''],
            [['users:create', 'not-an-email'], "valid-password\n"], [['users:create', $email], "short\n"],
            [['users:create', $email], ''], [['users:create', $email], str_repeat('x', 257) . "\n"]] as [$args, $input]) {
            [$code, $output, $error] = $this->console($args, $input);
            self::assertSame(1, $code);
            self::assertSame('', $output);
            self::assertNotSame('', $error);
            self::assertStringNotContainsString('never-print-this-secret', $error);
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $statement->execute([$email]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function test_list_returns_public_json_defaults_and_limit_and_rejects_invalid_options(): void
    {
        foreach ([$this->email(), $this->email()] as $email) {
            [$code, , $error] = $this->console(['users:create', $email], "list-password-123\n");
            self::assertSame(0, $code, $error);
        }
        foreach ([['users:list'], ['users:list', '--limit=1']] as $args) {
            [$code, $output, $error] = $this->console($args);
            self::assertSame(0, $code, $error);
            $users = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(count($args) === 1 ? 2 : 1, $users);
            foreach ($users as $user) {
                self::assertSame(['id', 'email', 'created_at'], array_keys($user));
            }
            self::assertStringNotContainsString('password', $output);
        }
        foreach (['--limit=', '--limit=0', '--limit=101', '--limit=abc', '--offset=1', '10'] as $arg) {
            [$code, $output, $error] = $this->console(['users:list', $arg]);
            self::assertSame(1, $code);
            self::assertSame('', $output);
            self::assertNotSame('', $error);
        }
        self::assertSame(1, $this->console(['users:list', '--limit=1', '--limit=2'])[0]);
    }

    private function email(): string
    {
        $email = 'cli-' . bin2hex(random_bytes(8)) . '@example.com';
        $this->emails[] = $email;
        return $email;
    }

    /** @param list<string> $args
     * @return array{int, string, string} */
    private function console(array $args, string $input = ''): array
    {
        $environment = array_map(static fn(mixed $value): string => (string) $value, Config::all());
        $environment['APP_DEBUG'] = '0';
        $empty = array_map(static fn(string $key): string => $key . '=', array_keys(array_filter($environment, static fn(string $value): bool => $value === '')));
        $process = proc_open(
            ['env', ...$empty, PHP_BINARY, dirname(__DIR__, 2) . '/bin/console', ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: $environment,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        self::assertIsString($output);
        self::assertIsString($error);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    }
}
