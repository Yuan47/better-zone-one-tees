<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class EmailProviderAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Enter a valid email address.');

            return;
        }
        $domain = strtolower((string) substr(strrchr((string) $value, '@') ?: '', 1));
        $typos = ['gmial.com', 'gmal.com', 'gmai.com', 'gmail.con', 'gamil.com', 'yaho.com', 'yahoo.con', 'outlok.com', 'hotmail.con'];

        if (! str_contains($domain, '.') || in_array($domain, $typos, true)
            || preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,63}$/D', $domain) !== 1) {
            $fail('Enter a valid email address from Gmail, Yahoo, Outlook, or another email provider. Check the domain spelling.');
        }
    }
}
