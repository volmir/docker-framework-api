<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
    return $GLOBALS['pdo'];
}

$environment = getenv('APP_ENV') ?: (PHP_SAPI === 'cli' ? 'develop' : App\Core\Environment::get());

$host = getenv('DB_HOST') ?: 'db';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'no_framework_api';
$user = getenv('DB_USER') ?: 'user';
$password = getenv('DB_PASSWORD') ?: 'password';

$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$GLOBALS['pdo'] = $pdo;
return $pdo;
