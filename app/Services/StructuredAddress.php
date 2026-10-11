<?php

namespace App\Services;

use Illuminate\Http\Request;

class StructuredAddress
{
    public const FIELDS = ['street_address', 'city', 'province', 'postal_code', 'country'];

    public function rules(bool $required = true): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            'street_address' => [$presence, 'string', 'max:200'],
            'city' => [$presence, 'string', 'max:100'],
            'province' => [$presence, 'string', 'max:100'],
            'postal_code' => [$presence, 'string', 'max:20'],
            'country' => [$presence, 'string', 'max:100'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ];
    }

    public function validatedInput(Request $request, bool $required = true): array
    {
        if (! $required) {
            // Saving an address is optional, but an entered address must be complete.
            $required = collect(['street_address', 'city', 'province', 'postal_code'])
                ->contains(fn ($field) => $request->filled($field));
        }

        $data = $request->validate($this->rules($required));
        $address = [];
        foreach (self::FIELDS as $field) {
            $value = isset($data[$field]) ? trim($data[$field]) : null;
            $address[$field] = $value === '' ? null : $value;
        }
        $address['country'] ??= 'Philippines';
        $address['lat'] = $data['lat'] ?? null;
        $address['lng'] = $data['lng'] ?? null;

        return $address;
    }

    public function format(array $address): string
    {
        return implode(', ', array_filter(array_map(
            fn ($field) => trim((string) ($address[$field] ?? '')),
            self::FIELDS,
        ), fn ($value) => $value !== ''));
    }
}
