<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Response;
use JsonException;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_json_response_preserves_unicode_and_slashes(): void
    {
        $response = Response::json(['url' => '/привет'], 201);

        self::assertSame(201, $response->status);
        self::assertSame('{"url":"/привет"}', $response->body);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
    }

    public function test_json_encoding_failures_throw(): void
    {
        $this->expectException(JsonException::class);
        Response::json(["\xFF"]);
    }

    public function test_error_details_are_an_object_even_when_empty(): void
    {
        self::assertSame('{"error":{"code":"not_found","message":"Not found","details":{}}}', Response::error(404, 'not_found', 'Not found')->body);
        self::assertSame('{"error":{"code":"invalid","message":"Invalid","details":{"field":"email"}}}', Response::error(400, 'invalid', 'Invalid', ['field' => 'email'])->body);
    }

    public function test_no_content_and_header_copy(): void
    {
        $original = Response::noContent();
        $copy = $original->withHeader('X-Test', 'first')->withHeader('x-test', 'second');

        self::assertSame(204, $copy->status);
        self::assertSame('', $copy->body);
        self::assertSame([], $original->headers);
        self::assertSame(['x-test' => 'second'], $copy->headers);
    }

    public function test_request_id_copy_preserves_error_details_objects_and_headers(): void
    {
        $original = Response::error(404, 'missing', 'Missing')->withHeader('Allow', 'GET');
        $response = $original->withRequestId('response-test-123');
        self::assertSame('{"error":{"code":"missing","message":"Missing","details":{},"request_id":"response-test-123"}}', $response->body);
        self::assertSame('GET', $response->headers['Allow']);
        self::assertSame('response-test-123', $response->headers['X-Request-Id']);
        self::assertArrayNotHasKey('X-Request-Id', $original->headers);
        self::assertStringNotContainsString('request_id', $original->body);
    }

    public function test_send_outputs_the_response_body_and_status(): void
    {
        $status = http_response_code();
        ob_start();
        try {
            Response::json(['status' => 'ok'], 201)->send();
            self::assertSame('{"status":"ok"}', ob_get_contents());
            self::assertSame(201, http_response_code());
        } finally {
            ob_end_clean();
            http_response_code(is_int($status) ? $status : 200);
        }
    }
}
