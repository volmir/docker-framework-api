<?php

declare(strict_types=1);

namespace App\Log;

final class Logger
{
    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write(['level' => 'error', 'message' => $message, 'context' => $context]);
    }
    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write(['level' => 'warning', 'message' => $message, 'context' => $context]);
    }
    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write(['level' => 'info', 'message' => $message, 'context' => $context]);
    }
    /** @param array<string, mixed> $record */
    public static function write(array $record): void
    {
        $record = Redactor::clean($record, context: true);
        if (isset($record['context'])) {
            $record['context'] = (object) $record['context'];
        }
        $json = json_encode(['ts' => gmdate('c')] + $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        file_put_contents('php://stderr', $json . PHP_EOL, FILE_APPEND);
    }
}
