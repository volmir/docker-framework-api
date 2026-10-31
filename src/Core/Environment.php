<?php
declare(strict_types=1);

namespace App\Core;

class Environment
{
    public const DEVELOP_SERVERS = [
        '127.0.0.1',
        '172.18.0.1',
        '172.18.0.2',
        '172.18.0.3',
        '172.18.0.4',
    ];

    /**
     * @return string
     */
    public static function get(): string
    {
        $environment = 'develop';
        if (!in_array($_SERVER['SERVER_ADDR'] ?? '', static::DEVELOP_SERVERS, true)) {
            $environment = 'production';
        }
        return $environment;
    }
}
