<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Validator\ItemValidator;

class ValidatorTest extends TestCase
{
    protected $validator;

    protected function setUp(): void
    {
        $this->validator = new ItemValidator();
    }

    public function test_validator_validate_true(): void
    {
        $this->assertTrue($this->validator->validateId(2));
        $this->assertTrue($this->validator->validateId(135));
    }

    public function test_validator_validate_false(): void
    {
        $this->assertFalse($this->validator->validateId(0));
        $this->assertFalse($this->validator->validateId(''));
        $this->assertFalse($this->validator->validateId('id'));
        $this->assertFalse($this->validator->validateId([]));
        $this->assertFalse($this->validator->validateId(null));
    }    
}
