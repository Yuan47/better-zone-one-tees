<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DannonNecklineSplit
{
    private const PRODUCTS = [
        'dannon-round-neck-v-neck' => ['DANNON - ROUND NECK / V-NECK', 'DANNON - ROUND NECK', 'dannon-v-neck', 'DANNON - V-NECK'],
        'dannon-round-neck-v-neck-kids' => ['DANNON - ROUND NECK / V-NECK KIDS', 'DANNON - ROUND NECK KIDS', 'dannon-v-neck-kids', 'DANNON - V-NECK KIDS'],
    ];

    public function apply(): array
    {
        return DB::transaction(function () {
            $counts = ['products_created' => 0, 'variants_created' => 0];
            foreach (array_keys(self::PRODUCTS) as $slug) {
                if ($product = Product::where('slug', $slug)->first()) {
                    $added = $this->split($product);
                    $counts['products_created'] += $added['products_created'];
                    $counts['variants_created'] += $added['variants_created'];
                }
            }

            return $counts;
        });
    }

    public function split(Product $product): array
    {
        if (! isset(self::PRODUCTS[$product->slug])) {
            return ['products_created' => 0, 'variants_created' => 0];
        }

        return DB::transaction(function () use ($product) {
            $source = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $marker = 'catalog.dannon_neckline_split.'.$source->slug;
            if (DB::table('shop_settings')->where('key', $marker)->exists()) {
                return ['products_created' => 0, 'variants_created' => 0];
            }
            [$legacyName, $roundName, $targetSlug, $targetName] = self::PRODUCTS[$source->slug];
            if (Product::where('slug', $targetSlug)->exists()) {
                throw new \RuntimeException('Cannot split '.$source->name.': '.$targetSlug.' already belongs to another product. Review that product before retrying; nothing was overwritten.');
            }

            // Copy cast values: raw size_chart JSON would be encoded a second time.
            $target = Product::create(Arr::except($source->attributesToArray(), ['id', 'created_at', 'updated_at', 'name', 'slug']) + [
                'name' => $targetName, 'slug' => $targetSlug,
            ]);
            // Keep the original catalog identity even after an owner has renamed a variant.
            $definitions = collect(json_decode(file_get_contents(database_path('data/catalog.json')), true, 512, JSON_THROW_ON_ERROR))
                ->firstWhere('slug', $source->slug);
            $originalLabels = [];
            foreach ($definitions['variants'] ?? [] as $variant) {
                $originalLabels[Sku::forVariant($source, $variant['size'], $variant['color'])] = [$variant['size'], $variant['color']];
            }
            $count = 0;
            foreach ($source->variants()->orderBy('id')->lockForUpdate()->get() as $variant) {
                [$skuSize, $skuColor] = $originalLabels[$variant->sku] ?? [$variant->size, $variant->color];
                $target->variants()->create(Arr::except($variant->getAttributes(), ['id', 'created_at', 'updated_at', 'product_id', 'sku', 'stock', 'reserved']) + [
                    'sku' => Sku::forNewVariant($target, $skuSize, $skuColor), 'stock' => 0, 'reserved' => 0,
                ]);
                $count++;
            }
            // Only the known combined default title is revised; owner-written titles stay intact.
            if ($source->name === $legacyName) {
                $source->update(['name' => $roundName]);
            }
            DB::table('shop_settings')->insert(['key' => $marker, 'value' => json_encode([
                'source_id' => $source->id, 'target_id' => $target->id, 'applied_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR)]);

            return ['products_created' => 1, 'variants_created' => $count];
        });
    }
}
