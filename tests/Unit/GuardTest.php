<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Auth\Guard;

class GuardTest extends TestCase
{
    protected $guard;

    protected function setUp(): void
    {
        $this->guard = new Guard();
    }

    public function test_guard_check_correct_token(): void
    {
        $headers = ['api-key' => '2c8aa07dffafa3008cbe6a3b2e398c19c1d9e23a'];
        $this->assertTrue($this->guard->authToken($headers));
    }

    public function test_guard_not_check_incorrect_token(): void
    {
        $headers = ['api-key' => ''];
        $this->assertFalse($this->guard->authToken($headers));
    }    
}
