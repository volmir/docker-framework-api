<?php
declare(strict_types=1);

namespace App\Core;

class Validator
{
    /** @var list<string> */
    public array $errors = [];

    public function setError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function clear(): void
    {
        $this->errors = [];
    }

    public function isValid(): bool
    {
        return count($this->errors) === 0;
    }
}
