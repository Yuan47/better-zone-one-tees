<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CatalogBatch
{
    private const ARCHIVE_MARKER = 'catalog.first_batch.imported';

    private const DEMO_SLUGS = [
        'everyday-cotton-tee', 'the-classic-crew', 'relaxed-pocket-tee', 'essential-polo',
        'active-dri-fit-tee', 'weekend-oversized-tee', 'everyday-kids-tee', 'classic-pique-polo',
    ];

    public function import(): array
    {
        $catalog = json_decode(file_get_contents(database_path('data/catalog.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->validate($catalog);

        return DB::transaction(function () use ($catalog) {
            $counts = ['products_created' => 0, 'variants_created' => 0, 'samples_archived' => 0];
            foreach ($catalog as $line) {
                $product = Product::firstOrCreate(['slug' => $line['slug']], [
                    'name' => $line['name'],
                    'category' => '',
                    'description' => $line['description'],
                    'art_color' => $line['art_color'],
                    'size_chart' => $line['size_chart'],
                    'size_chart_note' => $line['size_chart_note'],
                    'active' => true,
                ]);
                $counts['products_created'] += (int) $product->wasRecentlyCreated;

                foreach ($line['variants'] as $lineVariant) {
                    $variant = $product->variants()->firstOrCreate([
                        'sku' => Sku::forVariant($product, $lineVariant['size'], $lineVariant['color']),
                    ], $lineVariant + ['stock' => 0, 'reserved' => 0]);
                    $counts['variants_created'] += (int) $variant->wasRecentlyCreated;
                }
            }

            // Keep existing orders and their variants intact; archive the original demo catalog once.
            if (! DB::table('shop_settings')->where('key', self::ARCHIVE_MARKER)->exists()) {
                $counts['samples_archived'] = Product::whereIn('slug', self::DEMO_SLUGS)
                    ->where('active', true)->update(['active' => false]);
                DB::table('shop_settings')->insert(['key' => self::ARCHIVE_MARKER, 'value' => now()->toIso8601String()]);
            }

            return $counts;
        });
    }

    private function validate(array $catalog): void
    {
        $seenSlugs = [];
        foreach ($catalog as $line) {
            if (empty($line['name']) || empty($line['slug']) || isset($seenSlugs[$line['slug']])) {
                throw new \UnexpectedValueException('Catalog product names and unique slugs are required.');
            }
            $seenSlugs[$line['slug']] = true;
            $seenVariants = [];
            foreach ($line['variants'] as $variant) {
                $key = mb_strtolower($variant['size'].'|'.$variant['color']);
                if (empty($variant['size']) || empty($variant['color']) || isset($seenVariants[$key])
                    || ! in_array($variant['color_group'], ['WHITE', 'COLORED'], true)) {
                    throw new \UnexpectedValueException('Catalog variants need a unique size and color, and a valid color group.');
                }
                $seenVariants[$key] = true;
                foreach (['retail_price', 'wholesale_price', 'reseller_price'] as $key) {
                    if (! is_int($variant[$key]) || $variant[$key] < 0) {
                        throw new \UnexpectedValueException('Catalog prices must be non-negative integer centavos.');
                    }
                }
                if ($variant['reseller_price'] > $variant['wholesale_price'] || $variant['wholesale_price'] > $variant['retail_price']) {
                    throw new \UnexpectedValueException('Catalog price tiers must follow re-seller, wholesale, then retail.');
                }
            }
        }
    }
}
