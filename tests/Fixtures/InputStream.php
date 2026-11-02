<?php
declare(strict_types=1);

namespace App\Tests\Fixtures;

// Supplies php://input in CLI tests; Request itself still reads its normal stream.
final class InputStream
{
    public mixed $context;
    public static string $body = '';
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return $path === 'php://input';
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
