<?php

declare(strict_types=1);

namespace App\Http;

use JsonException;
use RuntimeException;

final readonly class Request
{
    private const int MAX_BODY_SIZE = 1_048_576;

    public string $method;
    public string $path;
    /** @var array<string, string> */
    public array $headers;

    /** @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @param array<string, mixed> $attributes */
    public function __construct(
        string $method,
        string $path,
        public array $query = [],
        array $headers = [],
        public array $body = [],
        public array $attributes = [],
    ) {
        $this->method = strtoupper($method);
        $this->path = rtrim($path, '/') ?: '/';
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function withAttribute(string $name, mixed $value): static
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;

        return new static($this->method, $this->path, $this->query, $this->headers, $this->body, $attributes);
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        if ((int) ($headers['content-length'] ?? 0) > self::MAX_BODY_SIZE) {
            throw new HttpException(413, 'payload_too_large', 'Request body too large');
        }

        // One extra byte detects oversized streams without Content-Length.
        $raw = file_get_contents('php://input', length: self::MAX_BODY_SIZE + 1);
        if ($raw === false) {
            throw new RuntimeException('Unable to read request body');
        }
        if (strlen($raw) > self::MAX_BODY_SIZE) {
            throw new HttpException(413, 'payload_too_large', 'Request body too large');
        }

        $body = [];
        $contentType = strtolower(trim(explode(';', $headers['content-type'] ?? '', 2)[0]));
        if ($contentType === 'application/json') {
            try {
                $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new HttpException(400, 'invalid_json', 'Expected a valid JSON object');
            }
            if (!is_array($body) || !str_starts_with(ltrim($raw), '{')) {
                throw new HttpException(400, 'invalid_json', 'Expected a valid JSON object');
            }
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET, $headers, $body);
    }
}
