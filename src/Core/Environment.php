<?php

namespace App\Core;

class Environment
{
    const DEVELOP_SERVERS = [
        '127.0.0.1',
        '172.18.0.3',
    ];

    /**
     * @return sting
     */
    public static function get() 
    {
        $environment = 'develop';     
        if (!in_array($_SERVER['SERVER_ADDR'], static::DEVELOP_SERVERS)) {
            $environment = 'production'; 
        }        
        return $environment;
    }
}