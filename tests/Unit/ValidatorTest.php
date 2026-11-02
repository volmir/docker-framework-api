<?php
declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\HttpException;
use App\Http\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /** @param array<string, mixed> $data
     * @param array<string, mixed> $expected */
    #[DataProvider('cases')]
    public function test_rules(string $rules, array $data, array $expected, bool $invalid = false): void
    {
        try {
            $result = Validator::validate($data, ['field' => $rules]);
            self::assertFalse($invalid, 'Invalid input was accepted');
            self::assertSame($expected, $result);
        } catch (HttpException $error) {
            self::assertTrue($invalid, 'Valid input was rejected');
            self::assertSame(422, $error->status);
            self::assertSame('validation_failed', $error->errorCode);
            self::assertSame('Validation failed', $error->getMessage());
            self::assertSame(['field'], array_keys($error->details));
            self::assertNotEmpty($error->details['field']);
            foreach ($error->details['field'] as $message) {
                self::assertIsString($message);
                self::assertNotSame('', $message);
            }
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>, array<string, mixed>, 3?:bool}> */
    public static function cases(): iterable
    {
        yield 'required missing' => ['required', [], [], true];
        foreach ([null, '', []] as $index => $value) {
            yield "required empty {$index}" => ['required', ['field' => $value], [], true];
        }
        foreach ([0, false, '0'] as $index => $value) {
            yield "required present {$index}" => ['required', ['field' => $value], ['field' => $value]];
        }
        yield 'optional omitted' => ['string', ['extra' => 'discard'], []];
        yield 'nullable missing' => ['nullable|int', [], []];
        yield 'nullable null' => ['nullable|int|min:1', ['field' => null], ['field' => null]];
        yield 'nullable present' => ['nullable|int', ['field' => '2'], ['field' => 2]];
        yield 'nullable does not override required' => ['nullable|required|int', ['field' => null], [], true];
        yield 'nullable empty is not null' => ['nullable|int', ['field' => ''], [], true];
        yield 'string' => ['string', ['field' => 'hello', 'extra' => 42], ['field' => 'hello']];
        yield 'empty string' => ['string', ['field' => ''], ['field' => '']];
        foreach ([42, false, [], null] as $index => $value) {
            yield "invalid string {$index}" => ['string', ['field' => $value], [], true];
        }
        foreach ([42, '42', '-3', '0', (string) PHP_INT_MAX, (string) PHP_INT_MIN] as $index => $value) {
            yield "integer {$index}" => ['int', ['field' => $value], ['field' => (int) $value]];
        }
        foreach ([true, false, 1.0, '1.2', '12x', '', [], null, (string) PHP_INT_MAX . '0'] as $index => $value) {
            yield "invalid integer {$index}" => ['int', ['field' => $value], [], true];
        }
        foreach ([true, 1, '1', 'true', 'yes', 'on'] as $index => $value) {
            yield "true boolean {$index}" => ['bool', ['field' => $value], ['field' => true]];
        }
        foreach ([false, 0, '0', 'false', 'no', 'off'] as $index => $value) {
            yield "false boolean {$index}" => ['bool', ['field' => $value], ['field' => false]];
        }
        foreach ([2, 1.0, '', 'maybe', [], null] as $index => $value) {
            yield "invalid boolean {$index}" => ['bool', ['field' => $value], [], true];
        }
        yield 'email' => ['email', ['field' => 'test@example.com'], ['field' => 'test@example.com']];
        foreach (['bad', '', [], 42] as $index => $value) {
            yield "invalid email {$index}" => ['email', ['field' => $value], [], true];
        }
        yield 'unicode minimum boundary' => ['string|min:3', ['field' => 'мир'], ['field' => 'мир']];
        yield 'unicode too short' => ['string|min:4', ['field' => 'мир'], [], true];
        yield 'unicode maximum boundary' => ['string|max:3', ['field' => 'мир'], ['field' => 'мир']];
        yield 'unicode too long' => ['string|max:2', ['field' => 'мир'], [], true];
        yield 'integer minimum boundary' => ['int|min:10', ['field' => '10'], ['field' => 10]];
        yield 'integer below minimum' => ['int|min:10', ['field' => '9'], [], true];
        yield 'integer maximum boundary' => ['int|max:10', ['field' => '10'], ['field' => 10]];
        yield 'integer above maximum' => ['int|max:10', ['field' => '11'], [], true];
        yield 'negative integer boundary' => ['int|min:-3|max:-1', ['field' => '-3'], ['field' => -3]];
        yield 'in list' => ['in:a,b,c', ['field' => 'b'], ['field' => 'b']];
        yield 'in list after conversion' => ['int|in:1,2,3', ['field' => '2'], ['field' => 2]];
        yield 'outside list' => ['in:a,b,c', ['field' => 'd'], [], true];
        yield 'in is case sensitive' => ['in:a,b,c', ['field' => 'A'], [], true];
        yield 'in rejects arrays' => ['in:a,b,c', ['field' => []], [], true];
        yield 'limits reject unsupported types' => ['min:1|max:2', ['field' => []], [], true];
    }

    public function test_errors_are_aggregated_by_field_without_echoing_input(): void
    {
        try {
            Validator::validate(['email' => 'secret-input', 'password' => 'short'], [
                'email' => 'required|string|email|max:3', 'password' => 'required|string|min:8',
            ]);
            self::fail('Invalid input was accepted');
        } catch (HttpException $error) {
            self::assertSame(['email', 'password'], array_keys($error->details));
            self::assertCount(2, $error->details['email']);
            self::assertCount(1, $error->details['password']);
            self::assertStringNotContainsString('secret-input', json_encode($error->details, JSON_THROW_ON_ERROR));
        }
    }

    public function test_only_declared_fields_are_returned_in_rule_order(): void
    {
        self::assertSame(['count' => 2, 'enabled' => false], Validator::validate([
            'extra' => 'discard', 'enabled' => 'false', 'count' => '2',
        ], ['count' => 'int', 'enabled' => 'bool', 'missing' => 'string']));
        self::assertSame([], Validator::validate(['extra' => 'discard'], []));
    }

    public function test_unknown_rules_are_configuration_errors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::validate(['field' => 'value'], ['field' => 'typo']);
    }
}
