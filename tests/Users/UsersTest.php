<?php

declare(strict_types=1);

namespace App\Tests\Users;

use App\Tests\DatabaseTestCase;
use App\Users\EmailTaken;
use App\Users\Users;

final class UsersTest extends DatabaseTestCase
{
    public function test_create_hashes_password_and_find_returns_only_public_fields(): void
    {
        $users = new Users($this->connection);
        $password = 'domain-password-123';
        $user = $users->create('domain@example.com', $password);
        self::assertSame(['id', 'email', 'created_at'], array_keys($user));
        self::assertSame('domain@example.com', $user['email']);
        self::assertSame($user, $users->find($user['id']));
        self::assertNull($users->find(0));
        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([$user['id']]);
        $hash = $statement->fetchColumn();
        self::assertIsString($hash);
        self::assertTrue(password_verify($password, $hash));
        self::assertSame('argon2id', password_get_info($hash)['algoName']);
    }

    public function test_list_uses_limit_offset_and_id_order_without_password_hashes(): void
    {
        $users = new Users($this->connection);
        $first = $users->create('first-domain@example.com', 'domain-password-123');
        $second = $users->create('second-domain@example.com', 'domain-password-123');
        self::assertSame([$first, $second], $users->list(20, 0));
        self::assertSame([$second], $users->list(1, 1));
        self::assertSame([], $users->list(20, 2));
    }

    public function test_duplicate_email_throws_a_domain_exception_and_preserves_the_user(): void
    {
        $users = new Users($this->connection);
        $user = $users->create('duplicate-domain@example.com', 'domain-password-123');
        try {
            $users->create($user['email'], 'replacement-password');
            self::fail('Expected EmailTaken');
        } catch (EmailTaken $error) {
            self::assertSame('Email is already taken', $error->getMessage());
            self::assertInstanceOf(\PDOException::class, $error->getPrevious());
        }
        self::assertSame([$user], $users->list(20, 0));
    }
}
