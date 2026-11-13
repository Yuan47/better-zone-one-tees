<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;

class VariantSizes
{
    public const ADULT = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];

    public static function choices(Product $product, ?Variant $variant = null): array
    {
        $sizes = self::ADULT;
        if (str_contains(strtoupper($product->name ?? ''), 'KIDS')) {
            $sizes = ['4', '5', '6', '8', '9', '10', '11', '12', '#4', '#6', '#8', '#10', '#12', '#14', '#16', '#18', '#20'];
        }
        if ($variant && ! in_array(strtoupper($variant->size), $sizes, true)) {
            $sizes[] = strtoupper($variant->size);
        }

        return $sizes;
    }
}
