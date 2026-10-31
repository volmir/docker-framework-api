<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
    return $GLOBALS['pdo'];
}

$environment = getenv('APP_ENV') ?: (PHP_SAPI === 'cli' ? 'develop' : App\Core\Environment::get());
$config = require __DIR__ . '/../config/main.php';
$database = $config['db'][$environment] ?? $config['db']['develop'];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $database['host'],
    $database['port'],
    $database['dbname']
);
$pdo = new PDO($dsn, $database['username'], $database['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$GLOBALS['pdo'] = $pdo;
return $pdo;
