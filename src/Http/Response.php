<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $code, string $message, array $details = []): self
    {
        return self::json(['error' => ['code' => $code, 'message' => $message, 'details' => (object) $details]], $status);
    }

    public function withHeader(string $name, string $value): static
    {
        $response = clone $this;
        foreach (array_keys($response->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($response->headers[$existing]);
            }
        }
        $response->headers[$name] = $value;

        return $response;
    }

    public function withRequestId(string $id): static
    {
        $response = $this->withHeader('X-Request-Id', $id);
        if ($response->status >= 400) {
            $body = json_decode($response->body, flags: JSON_THROW_ON_ERROR);
            if ($body instanceof \stdClass && isset($body->error) && $body->error instanceof \stdClass) {
                $body->error->request_id = $id;
                $response->body = self::json($body)->body;
            }
        }
        return $response;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
