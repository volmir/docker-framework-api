<?php
declare(strict_types=1);

namespace App\Core;

class Request
{
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_PATCH = 'PATCH';
    public const METHOD_DELETE = 'DELETE';

    public string $requestMethod;

    /** @var array<string, mixed> */
    public array $queryData = [];

    /** @var array<string, mixed> */
    public array $formData = [];

    /** @var array<string, string|string[]> */
    protected array $headers = [];

    public function __construct()
    {
        $parseUrl = parse_url($_SERVER['REQUEST_URI'] ?? '/');
        parse_str(($parseUrl['query'] ?? ''), $this->queryData);

        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? self::METHOD_GET;
        $this->formData = $this->getFormData();

        $this->setHeaders();
    }

    protected function setHeaders(): void
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return array
     */
    public function getFormData(): array
    {
        // GET parameters are provided by PHP's query parser.
        if ($this->requestMethod === self::METHOD_GET) {
            return $_GET;
        }

        return $this->getBody();
    }

    /**
     * Parse a JSON or URL-encoded request body.
     *
     * @return array
     * @throws \UnexpectedValueException When the JSON body is invalid.
     */
    public function getBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $contentType = strtolower(trim(explode(';', $contentType, 2)[0]));
        $body = $this->readInput();

        if ($contentType === 'application/json') {
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \UnexpectedValueException(
                    'Invalid JSON request body: ' . json_last_error_msg()
                );
            }

            if (!is_array($data)) {
                throw new \UnexpectedValueException('JSON request body must be an object or array.');
            }

            return $data;
        }

        if ($contentType === 'application/x-www-form-urlencoded') {
            parse_str($body, $data);
            return $data;
        }

        return [];
    }

    protected function readInput(): string
    {
        $body = file_get_contents('php://input');
        return $body === false ? '' : $body;
    }
}
