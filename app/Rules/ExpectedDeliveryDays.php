<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ExpectedDeliveryDays implements ValidationRule
{
    public static function valid(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A[0-9]{1,3}(?:-[0-9]{1,3})?\z/D', $value) !== 1) {
            return false;
        }

        $days = explode('-', $value);

        return count($days) === 1 || (int) $days[0] <= (int) $days[1];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valid($value)) {
            $fail('Enter delivery days as a number or an ascending range, such as 3 or 1-2. Use only digits and one optional hyphen, with each number between 0 and 999.');
        }
    }
}
