<?php
declare(strict_types=1);
namespace App\Database;
final class TracedStatement extends \PDOStatement
{
    protected function __construct() {}
    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        return QueryLog::measure($this->queryString, fn() => parent::execute($params));
    }
}
