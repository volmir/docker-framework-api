<?php

namespace App\Tests\Feature;

use App\Core\Router;
use PHPUnit\Framework\TestCase;

class ApiUsersFeatureTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI']);
        http_response_code(200);
    }

    public function test_get_api_users_returns_json_not_found_response(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/users';
        http_response_code(200);

        ob_start();
        (new Router())->dispatch();
        $body = ob_get_clean();

        self::assertSame(404, http_response_code());
        self::assertSame(['error' => 'Not Found'], json_decode($body, true));
        self::assertSame(JSON_ERROR_NONE, json_last_error());
    }
}
