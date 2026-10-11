<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Str;

class Sku
{
    public static function forNewVariant(Product $product, string $size, string $color): string
    {
        $base = self::forVariant($product, $size, $color);
        $sku = $base;
        $number = 1;
        // Existing SKUs stay stable when an owner renames a variant.
        while (Variant::where('sku', $sku)->exists()) {
            $sku = $base.'-'.(++$number);
        }

        return $sku;
    }

    public static function forVariant(Product|int $product, string $size, string $color): string
    {
        $productId = $product instanceof Product ? $product->getKey() : $product;
        if (! $productId || $productId < 1) {
            throw new \InvalidArgumentException('Save the product before generating a variant SKU.');
        }

        $sizeCode = strtoupper(substr(Str::slug($size), 0, 16)) ?: 'SIZE';
        $colorCode = strtoupper(substr(Str::slug($color), 0, 32)) ?: 'COLOR';
        $suffix = strtoupper(substr(hash('sha256', mb_strtolower(trim($size)).'|'.mb_strtolower(trim($color))), 0, 8));

        return 'ZOT-'.str_pad((string) $productId, 4, '0', STR_PAD_LEFT).'-'.$sizeCode.'-'.$colorCode.'-'.$suffix;
    }
}
