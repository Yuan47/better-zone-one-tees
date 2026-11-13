<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhilippineMobileNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^09[0-9]{9}$/D', $value) !== 1) {
            $fail('Enter an 11-digit Philippine mobile number beginning with 09.');
        }
    }

    public static function local(?string $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\+639[0-9]{9}$/D', $value) === 1
            ? '0'.substr($value, 3)
            : $value;
    }
}
