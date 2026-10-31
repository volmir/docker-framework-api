<?php
declare(strict_types=1);

namespace App\Core\Log\Handler;

interface HandlerInterface
{
    public const DEFAULT_FORMAT = '%timestamp% [%level%]: %message%';
    public function handle(array $vars): void;
}
