<?php
declare(strict_types=1);

namespace App;

use App\Log\Redactor;
use App\Middleware\{ErrorHandler, RequestId};
use ErrorException, Throwable;
final class Errors
{
    private static string $reserve = '';
    public static function register(): void
    {
        self::$reserve = str_repeat(' ', 262144);
        ini_set('display_errors', '0');
        ini_set('log_errors', '0'); // The handlers log once, with secrets removed.
        set_error_handler(static fn(int $level, string $message, string $file, int $line): bool => (error_reporting() & $level) ? throw new ErrorException($message, 0, $level, $file, $line) : true);
        set_exception_handler(self::handle(...));
        register_shutdown_function(static function (): void {
            if (self::$reserve !== '') { self::$reserve = ''; }
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) { self::handle(new ErrorException(explode("\nStack trace:", $error['message'], 2)[0], 0, $error['type'], $error['file'], $error['line'])); }
        });
        foreach (['Config', 'Http/Middleware', 'Http/Response', 'Http/HttpException', 'Log/Redactor', 'Log/Logger', 'Middleware/ErrorHandler', 'Middleware/RequestId'] as $file) { require_once __DIR__ . '/' . $file . '.php'; }
        if (PHP_SAPI !== 'cli') { $_SERVER['HTTP_X_REQUEST_ID'] = RequestId::resolve($_SERVER['HTTP_X_REQUEST_ID'] ?? ''); ob_start(); }
    }
    public static function handle(Throwable $error): void
    {
        try { $response = ErrorHandler::response($error); }
        catch (Throwable) {
            $id = bin2hex(random_bytes(8));
            file_put_contents('php://stderr', json_encode(['level' => 'error', 'message' => 'Error formatter unavailable', 'context' => ['class' => $error::class, 'message' => 'Startup error', 'file' => 'bootstrap:' . $error->getLine(), 'request_id' => $id]]) . PHP_EOL);
            if (PHP_SAPI === 'cli') { fwrite(STDERR, "Error formatter unavailable\n"); exit(1); }
            if (!headers_sent()) { if (ob_get_level() > 0) { ob_clean(); } http_response_code(500); header('Content-Type: application/json; charset=utf-8'); header('X-Request-Id: ' . $id); echo json_encode(['error' => ['code' => 'internal_error', 'message' => 'Internal Server Error', 'details' => (object) [], 'request_id' => $id]]); }
            return;
        }
        if (PHP_SAPI === 'cli') { fwrite(STDERR, $error::class . ': ' . Redactor::clean($error->getMessage()) . PHP_EOL); exit(1); }
        if (!headers_sent()) { if (ob_get_level() > 0) { ob_clean(); } $response->send(); }
    }
}
