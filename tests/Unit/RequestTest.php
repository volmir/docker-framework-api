<?php

namespace App\Tests\Unit;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['CONTENT_TYPE']);
        $_GET = [];
    }

    public function test_get_body_decodes_json(): void
    {
        $request = $this->makeRequest('application/json; charset=utf-8', '{"name":"Ada"}');

        $this->assertSame(['name' => 'Ada'], $request->getBody());
    }

    public function test_get_body_parses_url_encoded_data(): void
    {
        $request = $this->makeRequest(
            'application/x-www-form-urlencoded',
            'name=Ada+Lovelace&tags%5B%5D=math'
        );

        $this->assertSame(['name' => 'Ada Lovelace', 'tags' => ['math']], $request->getBody());
    }

    public function test_invalid_json_throws_an_exception(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid JSON request body:');
        $this->makeRequest('application/json', '{invalid');
    }

    private function makeRequest(string $contentType, string $body): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['REQUEST_URI'] = '/items';
        $_SERVER['CONTENT_TYPE'] = $contentType;
        $_GET = [];

        return new class($body) extends Request {
            private string $body;

            public function __construct(string $body)
            {
                $this->body = $body;
                parent::__construct();
            }

            protected function readInput(): string
            {
                return $this->body;
            }
        };
    }
}
