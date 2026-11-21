<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;

class VariantSizes
{
    public const ADULT = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];

    public static function label(?string $size): string
    {
        return preg_replace('/^#(\d+)$/D', '$1', trim((string) $size));
    }

    public static function aliases(string $size): array
    {
        $label = self::label($size);

        return ctype_digit($label) ? [$label, '#'.$label] : [$size];
    }

    /** One visible option per size; its value keeps the existing catalog identity. */
    public static function options(Product $product, ?Variant $variant = null, ?string $selected = null): array
    {
        $choices = self::choices($product, $variant);
        $preferred = collect([$variant?->size])
            ->merge($product->variants->pluck('size'))->push($selected)->merge($choices)
            ->filter(fn ($size) => in_array($size, $choices, true))
            ->unique(fn ($size) => self::label($size));
        $values = $preferred->keyBy(fn ($size) => self::label($size));

        return collect($choices)->unique(fn ($size) => self::label($size))
            ->map(fn ($size) => ['value' => (string) $values->get(self::label($size)), 'label' => self::label($size)])
            ->values()->all();
    }

    public static function choices(Product $product, ?Variant $variant = null): array
    {
        $sizes = self::ADULT;
        if (str_contains(strtoupper($product->name ?? ''), 'KIDS')) {
            $sizes = ['4', '5', '6', '8', '9', '10', '11', '12', '14', '16', '18', '20', '#4', '#6', '#8', '#10', '#12', '#14', '#16', '#18', '#20'];
        }
        if ($variant && ! in_array(strtoupper($variant->size), $sizes, true)) {
            $sizes[] = strtoupper($variant->size);
        }

        return $sizes;
    }
}
