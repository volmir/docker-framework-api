<?php
declare(strict_types=1);

return [
    'run' => function (PDO $pdo): void {
        $statement = $pdo->prepare(
            'INSERT IGNORE INTO users (email, password_hash) VALUES (?, ?)'
        );
        foreach (['alice@example.com', 'bob@example.com'] as $email) {
            $statement->execute([$email, password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID)]);
        }
    },
];
