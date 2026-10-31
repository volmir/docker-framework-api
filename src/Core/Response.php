<?php
declare(strict_types=1);

namespace App\Core;

class Response
{
    /** @var list<string> */
    protected array $headers = [];

    protected mixed $content = null;

    public function setContent(mixed $content = ''): void
    {
        $this->content = $content;
    }

    public function getContent(): mixed
    {
        return $this->content;
    }

    public function setHeader(string $value): void
    {
        $this->headers[] = $value;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function sendHeaders(): void
    {
        foreach ($this->headers as $header) {
            header($header);
        }
    }

    public static function redirect(string $url): void
    {
        $response = new self();
        $response->setHeader('Location: ' . $url);
        $response->sendHeaders();
    }

    public function output(): void
    {
        $this->sendHeaders();
        echo $this->getContent();
    }

    public function outputJson(): void
    {
        $this->setHeader('Content-Type: application/json');
        $this->sendHeaders();
        echo json_encode($this->getContent());
    }
}
