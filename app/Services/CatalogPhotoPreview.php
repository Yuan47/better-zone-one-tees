<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;

class CatalogPhotoPreview
{
    private const COLORS = [
        'white' => '#FCFCF9', 'red' => '#CF3843', 'mandarin' => '#F27B2B', 'maroon' => '#70263D',
        'batis' => '#2B99B3', 'teal' => '#217E83', 'light blue' => '#95CBE7', 'fatigue' => '#697047',
        'gray' => '#92979E', 'pool green' => '#47C2A4', 'gold' => '#DDA628', 'yellow' => '#F6D437',
        'peach' => '#F6B59D', 'light yellow' => '#FFF2A5', 'green' => '#2F9854', 'light green' => '#B0D89B',
        'light red' => '#ED9797', 'dark green' => '#194E3B', 'dark red' => '#841E29', 'dark yellow' => '#A27B12',
        'black' => '#25262A', 'ivory' => '#F1E7D2', 'plain' => '#F4F4EF', 'reflector' => '#60717C', 'sage' => '#A5B6A0',
    ];

    private const STYLES = [
        'dannon-round-neck-v-neck' => 'round-neck',
        'dannon-v-neck' => 'v-neck',
        'dannon-round-neck-v-neck-kids' => 'round-neck-kids',
        'dannon-v-neck-kids' => 'v-neck-kids',
        'dannon-with-collar' => 'polo',
        'lucky-hanna-v-neck' => 'v-neck',
        'lucky-hanna-with-collar' => 'polo',
        'thanos' => 'round-neck',
        'yalex-round-neck' => 'round-neck',
        'yalex-kids' => 'round-neck-kids',
        'yalex-v-neck' => 'v-neck',
        'yalex-with-collar' => 'polo',
        'softex-round-neck' => 'round-neck',
        'softex-kids' => 'round-neck-kids',
        'softex-with-collar' => 'polo',
        'softex-long-sleeve' => 'long-sleeve',
        'flipper' => 'round-neck',
        'apple-tees-dri-fit-round-neck' => 'round-neck',
        'apple-tees-dri-fit-with-collar' => 'polo',
        'apple-tees-dri-fit' => 'round-neck',
    ];

    public static function product(Product $product): ?string
    {
        if (! isset(self::STYLES[$product->slug]) || ! self::samplePath($product->image_path)
            || (! $product->image_path && filled($product->image_url))) {
            return null;
        }

        return asset('catalog-photos/'.self::STYLES[$product->slug].'.png');
    }

    public static function variant(Variant $variant, Product $product): ?string
    {
        if (! self::samplePath($variant->image_path)) {
            return null;
        }
        // A blank variant inherits an owner's product photograph rather than a demo.
        if (! $variant->image_path) {
            return self::product($product);
        }

        return isset(self::STYLES[$product->slug]) ? asset('catalog-photos/'.self::STYLES[$product->slug].'.png') : null;
    }

    public static function color(?string $hex): string
    {
        return is_string($hex) && preg_match('/^#[a-f0-9]{6}$/iD', $hex) ? $hex : '#ffffff';
    }

    public static function namedColor(?string $name): string
    {
        return self::COLORS[mb_strtolower(trim((string) $name))] ?? '#d8cdbb';
    }

    private static function samplePath(?string $path): bool
    {
        return blank($path) || (bool) preg_match('/^uploads\/variants\/democolor20261029_p[0-9]+_[a-z0-9_]+_[a-f0-9]{8}\.png$/D', $path);
    }
}
