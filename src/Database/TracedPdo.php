<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOStatement;

final class TracedPdo extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return QueryLog::measure($query, fn() => parent::query($query, $fetchMode, ...$fetchModeArgs));
    }
    public function exec(string $statement): int|false
    {
        return QueryLog::measure($statement, fn() => parent::exec($statement));
    }
}
