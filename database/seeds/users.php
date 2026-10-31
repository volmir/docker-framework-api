<?php
declare(strict_types=1);

return [
    'run' => function (PDO $pdo): void {
        $statement = $pdo->prepare(
            'INSERT INTO users (email, password_hash) VALUES (?, ?)'
        );
        $statement->execute(['alice@example.com', password_hash('secret', PASSWORD_ARGON2ID)]);
        $statement->execute(['bob@example.com', password_hash('secret', PASSWORD_ARGON2ID)]);
    },
];
