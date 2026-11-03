<?php

declare(strict_types=1);

/** @var array{up:callable(PDO):void, down:callable(PDO):void} $migration */
$migration = [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY users_email_unique (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE users");
    },
];

return $migration;
