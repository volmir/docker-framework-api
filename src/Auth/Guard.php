<?php

namespace App\Auth;

class Guard
{
    private $trustedTokens = [
        '2c8aa07dffafa3008cbe6a3b2e398c19c1d9e23a',
        '1e5b6fe3a6b09e22c1d9e23a3eeb09cc7e1e1ab7',
        'f556b3ab0839bc473434a59a98af38c464055124',
    ];

    public function authToken($headers): bool
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) == 'api-key' && in_array($value, $this->trustedTokens)) {
                return true;
            }
        }
        return false;
    }
}
