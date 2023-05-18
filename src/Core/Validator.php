<?php

namespace App\Core;

class Validator
{    
    public $errors = [];

    public function setError(string $error) 
    {
        $this->errors[] = $error;
    }

    public function getErrors(): array 
    {
        return $this->errors;
    } 

    public function clear()
    {
        $this->errors = [];
    }     
    
    public function isValid(): bool
    {
        if (count($this->errors) == 0) {
            return true;
        }
        return false;
    }     
}
