<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE users");
    },
];
