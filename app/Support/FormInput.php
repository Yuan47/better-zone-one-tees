<?php

namespace App\Support;

class FormInput
{
    public static function query(string $key, mixed $default = null): string|int|float|bool|null
    {
        $value = request()->query($key, $default);

        // Search controls accept scalar values. Ignore malformed URL arrays
        // without changing the request or the validation of submitted forms.
        return is_scalar($value) || $value === null
            ? $value
            : (is_scalar($default) || $default === null ? $default : null);
    }

    public static function old(string $key, mixed $default = null): string|int|float|bool|null
    {
        $value = old($key, $default);

        return is_scalar($value) || $value === null
            ? $value
            : (is_scalar($default) || $default === null ? $default : null);
    }

    public static function rows(string $key, array $default = []): array
    {
        $value = old($key, $default);
        $rows = is_array($value) ? $value : $default;

        // Invalid submissions must still render a correctable form. Validation
        // retains the original payload; only its displayed values are normalized.
        return array_map(static function ($row): array {
            if (! is_array($row)) {
                return [];
            }

            return array_map(static fn ($value) => is_scalar($value) || $value === null ? $value : null, $row);
        }, array_slice($rows, 0, 100, true));
    }
}
