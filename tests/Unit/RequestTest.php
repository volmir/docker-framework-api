<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\HttpException;
use App\Http\Request;
use App\Tests\Fixtures\InputStream;
use Error;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_normalizes_method_path_and_headers(): void
    {
        $request = new Request('get', '/users///', ['id' => '1'], ['X-Api-Key' => 'key']);
        self::assertSame('GET', $request->method);
        self::assertSame('/users', $request->path);
        self::assertSame(['x-api-key' => 'key'], $request->headers);
        self::assertSame('key', $request->header('X-API-KEY'));
        self::assertNull($request->header('missing'));
        self::assertSame('/', (new Request('GET', '/'))->path);
    }

    public function test_attributes_produce_an_immutable_copy(): void
    {
        $original = new Request('GET', '/users', attributes: ['user' => 1]);
        $copy = $original->withAttribute('user', 2)->withAttribute('scope', 'read');
        self::assertNotSame($original, $copy);
        self::assertSame(['user' => 1], $original->attributes);
        self::assertSame(['user' => 2, 'scope' => 'read'], $copy->attributes);
        $this->expectException(Error::class);
        (new \ReflectionProperty(Request::class, 'attributes'))->setValue($copy, ['user' => 3]);
    }

    public function test_globals_collect_headers_query_and_json_body(): void
    {
        $request = $this->fromInput('{"email":"user@example.com","nested":{"items":[1,2]}}', [
            'REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/users/?id=1',
            'HTTP_X_API_KEY' => 'key', 'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'Application/JSON; charset=utf-8', 'CONTENT_LENGTH' => '53',
        ], ['id' => '1']);
        self::assertSame('POST', $request->method);
        self::assertSame('/users', $request->path);
        self::assertSame(['id' => '1'], $request->query);
        self::assertSame(['email' => 'user@example.com', 'nested' => ['items' => [1, 2]]], $request->body);
        self::assertSame(['x-api-key' => 'key', 'accept' => 'application/json',
            'content-type' => 'Application/JSON; charset=utf-8', 'content-length' => '53'], $request->headers);
        self::assertSame([], $this->fromInput('{}', ['CONTENT_TYPE' => 'application/json'])->body);
    }

    public function test_other_media_types_and_post_globals_are_ignored(): void
    {
        foreach (['', 'text/plain', 'application/x-www-form-urlencoded', 'application/jsonp'] as $type) {
            $request = $this->fromInput('invalid JSON', ['CONTENT_TYPE' => $type]);
            self::assertSame([], $request->body);
        }
    }

    public function test_invalid_json_and_non_object_roots_are_400(): void
    {
        foreach (['', '{', '[]', '[{}]', 'null', 'true', '42', '"text"', "{\"bad\":\"\xFF\"}"] as $body) {
            try {
                $this->fromInput($body, ['CONTENT_TYPE' => 'application/json']);
                self::fail('Expected invalid JSON');
            } catch (HttpException $error) {
                self::assertSame(400, $error->status);
                self::assertSame('invalid_json', $error->errorCode);
            }
        }
    }

    public function test_exactly_one_mebibyte_is_accepted(): void
    {
        $body = json_encode(['data' => str_repeat('x', 1_048_576 - 11)], JSON_THROW_ON_ERROR);
        self::assertSame(1_048_576, strlen($body));
        $request = $this->fromInput($body, ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($body)]);
        self::assertSame(1_048_576 - 11, strlen($request->body['data']));
    }

    public function test_oversized_declared_missing_and_understated_lengths_are_413(): void
    {
        foreach ([['', ['CONTENT_LENGTH' => '1048577']], [str_repeat('x', 1_048_577), []],
            [str_repeat('x', 1_048_577), ['CONTENT_LENGTH' => '1']]] as [$body, $server]) {
            try {
                $this->fromInput($body, $server);
                self::fail('Expected oversized body');
            } catch (HttpException $error) {
                self::assertSame(413, $error->status);
                self::assertSame('payload_too_large', $error->errorCode);
            }
        }
    }

    /** @param array<string, string> $server
     * @param array<string, mixed> $query */
    private function fromInput(string $body, array $server, array $query = []): Request
    {
        [$originalServer, $originalGet, $originalPost] = [$_SERVER, $_GET, $_POST];
        InputStream::$body = $body;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', InputStream::class);
        try {
            $_SERVER = $server;
            $_GET = $query;
            $_POST = ['email' => 'ignored@example.com'];
            return Request::fromGlobals();
        } finally {
            stream_wrapper_restore('php');
            InputStream::$body = '';
            [$_SERVER, $_GET, $_POST] = [$originalServer, $originalGet, $originalPost];
        }
    }
}
