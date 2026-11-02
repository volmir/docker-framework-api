<?php
declare(strict_types=1);

use App\Config;
use App\Middleware\AccessLog;
use App\Middleware\Debug;
use App\Middleware\ErrorHandler;
use App\Middleware\RequestId;

$middleware = [ErrorHandler::class, RequestId::class, AccessLog::class];
if (Config::isDebug()) { array_unshift($middleware, Debug::class); }
return $middleware;
