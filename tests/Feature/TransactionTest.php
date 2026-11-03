<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Database\{Connection, TracedPdo};
use App\Tests\DatabaseTestCase;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class TransactionTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $emails = [];
    /** @var array<string, mixed> */
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
        parent::tearDown();
        $delete = (new Connection())->pdo()->prepare('DELETE FROM users WHERE email = ?');
        foreach ($this->emails as $email) {
            $delete->execute([$email]);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function debugModes(): iterable
    {
        yield 'plain PDO' => [false];
        yield 'debug PDO' => [true];
    }

    #[DataProvider('debugModes')]
    public function test_commit_returns_the_callback_result_and_is_visible_from_another_connection(bool $debug): void
    {
        $_ENV['APP_DEBUG'] = $debug ? '1' : '0';
        $connection = new Connection();
        self::assertInstanceOf($debug ? TracedPdo::class : PDO::class, $connection->pdo());
        $email = $this->email();
        $expected = (object) ['committed' => true];
        $result = $connection->transaction(function (Connection $received) use ($connection, $email, $expected): object {
            self::assertSame($connection, $received);
            self::assertTrue($received->pdo()->inTransaction());
            $this->insert($received, $email);
            self::assertSame($connection->pdo(), $received->pdo());
            self::assertSame(['email' => $email], $received->one('SELECT email FROM users WHERE email = ?', [$email]));
            self::assertSame([['email' => $email]], $received->all('SELECT email FROM users WHERE email = ?', [$email]));
            self::assertSame(0, $this->countEmail($email), 'Other connections cannot see the uncommitted helper write');
            return $expected;
        });
        self::assertSame($expected, $result);
        self::assertFalse($connection->pdo()->inTransaction());
        self::assertSame(1, $this->countEmail($email));
    }

    public function test_throwable_rolls_back_all_writes_and_rethrows_the_same_object(): void
    {
        foreach ([new RuntimeException('transaction failed'), new \Error('callback error')] as $expected) {
            $connection = new Connection();
            $emails = [$this->email(), $this->email()];
            try {
                $connection->transaction(function (Connection $received) use ($emails, $expected): never {
                    foreach ($emails as $email) {
                        $this->insert($received, $email);
                    }
                    throw $expected;
                });
                self::fail('Expected the callback error');
            } catch (\Throwable $actual) {
                self::assertSame($expected, $actual);
            }
            self::assertFalse($connection->pdo()->inTransaction());
            foreach ($emails as $email) {
                self::assertSame(0, $this->countEmail($email));
            }
        }
    }

    public function test_nested_call_is_rejected_and_the_outer_transaction_rolls_back(): void
    {
        $connection = new Connection();
        $email = $this->email();
        try {
            $connection->transaction(function (Connection $received) use ($email): void {
                $this->insert($received, $email);
                $received->transaction(static fn(Connection $_connection): never => self::fail('Nested callback must not execute'));
            });
            self::fail('Expected nested transaction rejection');
        } catch (LogicException $error) {
            self::assertStringContainsString('Nested transactions are not supported', $error->getMessage());
        }
        self::assertFalse($connection->pdo()->inTransaction());
        self::assertSame(0, $this->countEmail($email));
    }

    public function test_an_existing_transaction_is_left_active_when_the_guard_rejects_a_call(): void
    {
        try {
            $this->connection->transaction(static fn(Connection $_connection): never => self::fail('Callback must not execute'));
            self::fail('Expected existing transaction rejection');
        } catch (LogicException $error) {
            self::assertStringContainsString('already has an active transaction', $error->getMessage());
        }
        self::assertTrue($this->pdo->inTransaction());
    }

    public function test_an_inactive_transaction_is_not_rolled_back_again(): void
    {
        $connection = new Connection();
        $expected = new RuntimeException('already rolled back');
        try {
            $connection->transaction(static function (Connection $received) use ($expected): never {
                $received->pdo()->rollBack();
                throw $expected;
            });
            self::fail('Expected callback error');
        } catch (RuntimeException $actual) {
            self::assertSame($expected, $actual);
        }
        self::assertFalse($connection->pdo()->inTransaction());
    }

    private function email(): string
    {
        $email = 'transaction-' . bin2hex(random_bytes(8)) . '@example.com';
        $this->emails[] = $email;
        return $email;
    }

    private function insert(Connection $connection, string $email): void
    {
        $connection->run('INSERT INTO users (email, password_hash) VALUES (?, ?)', [$email, 'transaction-test-hash']);
    }

    private function countEmail(string $email): int
    {
        return (int) ((new Connection())->one('SELECT COUNT(*) AS total FROM users WHERE email = ?', [$email])['total'] ?? self::fail('Count query returned no row'));
    }
}
