<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

final class Validator
{
    /** @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @return array<string, mixed> */
    public static function validate(array $data, array $rules): array
    {
        $result = $errors = [];
        foreach ($rules as $field => $definition) {
            $checks = explode('|', $definition);
            $value = $data[$field] ?? null;
            if (in_array('required', $checks, true) && in_array($value, [null, '', []], true)) {
                $errors[$field][] = 'This field is required';
                continue;
            }
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if ($value === null && in_array('nullable', $checks, true)) {
                $result[$field] = null;
                continue;
            }
            foreach ($checks as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, '');
                $valid = match ($name) {
                    'required', 'nullable' => true,
                    'string' => is_string($value),
                    'int' => (is_int($value) || is_string($value))
                        && ($value = filter_var($value, FILTER_VALIDATE_INT)) !== false,
                    'bool' => $value !== '' && (is_bool($value) || is_int($value) || is_string($value))
                        && ($value = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)) !== null,
                    'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'min' => is_string($value) ? mb_strlen($value, 'UTF-8') >= (int) $argument
                        : is_int($value) && $value >= (int) $argument,
                    'max' => is_string($value) ? mb_strlen($value, 'UTF-8') <= (int) $argument
                        : is_int($value) && $value <= (int) $argument,
                    'in' => is_scalar($value) && in_array((string) $value, explode(',', $argument), true),
                    default => throw new InvalidArgumentException('Unknown validation rule: ' . $name),
                };
                if (!$valid) {
                    $errors[$field][] = 'Must satisfy rule: ' . $rule;
                    if (in_array($name, ['string', 'int', 'bool'], true)) {
                        break;
                    }
                }
            }
            $result[$field] = $value;
        }
        if ($errors !== []) {
            throw new HttpException(422, 'validation_failed', 'Validation failed', $errors);
        }
        return $result;
    }
}
