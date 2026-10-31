<?php
declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Log\Handler\HandlerInterface;
use Psr\Log\AbstractLogger;

class Logger extends AbstractLogger
{
    protected const DEFAULT_DATETIME_FORMAT = 'c';

    private HandlerInterface $handler;

    public function __construct(HandlerInterface $handler)
    {
        $this->handler = $handler;
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->handler->handle([
            'message' => self::interpolate((string) $message, $context),
            'level' => strtoupper((string) $level),
            'timestamp' => (new \DateTimeImmutable())->format(self::DEFAULT_DATETIME_FORMAT),
        ]);
    }

    protected static function interpolate(string $message, array $context = []): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        return strtr($message, $replace);
    }
}
