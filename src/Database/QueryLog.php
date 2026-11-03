<?php

declare(strict_types=1);

namespace App\Database;

use App\Log\Logger;

final class QueryLog
{
    /** @var list<array{sql:string, ms:float}> */
    public static array $queries = [];
    /** @template T
     * @param callable():T $run
     * @return T */
    public static function measure(string $sql, callable $run): mixed
    {
        $start = hrtime(true);
        try {
            return $run();
        } finally {
            $ms = (hrtime(true) - $start) / 1_000_000;
            self::$queries[] = ['sql' => $sql, 'ms' => $ms];
            if ($ms > 100) {
                Logger::warning('Slow query', ['sql' => $sql, 'ms' => $ms]);
            }
        }
    }
}
