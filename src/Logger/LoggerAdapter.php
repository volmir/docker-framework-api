<?php

namespace App\Logger;

use App\Core\Log\Handler\FileHandler;
use App\Core\Log\Logger;

class LoggerAdapter
{
    public static function init(): Logger
    {
        $logFileName = dirname(__DIR__) . '/../logs/' . date('Y-m-d') . '.log';
    
        $handler = new FileHandler($logFileName);
        return new Logger($handler);           
    }
}