<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\CatalogBatch;
use App\Services\OrderFlow;
use App\Services\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_permanent_catalog_keeps_the_first_six_products_and_every_requested_color_size_and_price(): void
    {
        $counts = app(CatalogBatch::class)->import();
        $this->assertSame(['products_created' => 18, 'variants_created' => 1049, 'samples_archived' => 0], $counts);
        $this->assertSame(18, Product::where('active', true)->count());
        $this->assertSame(1049, Variant::count());
        $this->assertSame(1049, Variant::distinct()->count('sku'));
        $this->assertSame(0, Variant::sum('stock'));
        $this->assertSame(0, Variant::sum('reserved'));

        $colors = ['Red', 'Mandarin', 'Maroon', 'Batis', 'Teal', 'Light Blue', 'Fatigue', 'Gray', 'Pool Green', 'Gold', 'Yellow', 'Peach', 'Light Yellow'];
        // Re-seller values independently transcribed from the owner's first batch price list.
        $expected = [
            'dannon-round-neck-v-neck' => [
                'WHITE' => ['XS' => 117, 'S' => 127, 'M' => 127, 'L' => 142, 'XL' => 142, '2XL' => 152, '3XL' => 162, '4XL' => 182, '5XL' => 182],
                'COLORED' => ['XS' => 137, 'S' => 152, 'M' => 152, 'L' => 167, 'XL' => 167, '2XL' => 187, '3XL' => 197, '4XL' => 217, '5XL' => 217],
            ],
            'dannon-with-collar' => [
                'WHITE' => ['S' => 187, 'M' => 187, 'L' => 197, 'XL' => 197, '2XL' => 217, '3XL' => 242],
                'COLORED' => ['S' => 217, 'M' => 217, 'L' => 232, 'XL' => 232, '2XL' => 257, '3XL' => 287, '4XL' => 287, '5XL' => 287],
            ],
            'lucky-hanna-v-neck' => [
                'WHITE' => ['S' => 125, 'M' => 125, 'L' => 125, 'XL' => 125, '2XL' => 145, '3XL' => 145],
                'COLORED' => ['S' => 150, 'M' => 150, 'L' => 150, 'XL' => 150, '2XL' => 180, '3XL' => 180],
            ],
            'lucky-hanna-with-collar' => [
                'WHITE' => ['S' => 169, 'M' => 169, 'L' => 169, 'XL' => 169, '2XL' => 179, '3XL' => 200, '4XL' => 200, '5XL' => 200],
                'COLORED' => ['S' => 189, 'M' => 189, 'L' => 189, 'XL' => 189, '2XL' => 204, '3XL' => 235, '4XL' => 235, '5XL' => 235],
            ],
            'dannon-round-neck-v-neck-kids' => [
                'WHITE' => ['4' => 80, '5' => 80, '6' => 80, '8' => 90, '9' => 90, '10' => 90, '11' => 90, '12' => 90],
                'COLORED' => ['4' => 106, '5' => 106, '6' => 106, '8' => 111, '9' => 111, '10' => 111, '11' => 111, '12' => 111],
            ],
            'thanos' => [
                'WHITE' => ['XS' => 96, 'S' => 102, 'M' => 102, 'L' => 110, 'XL' => 110, '2XL' => 122],
                'COLORED' => ['XS' => 107, 'S' => 114, 'M' => 114, 'L' => 124, 'XL' => 124, '2XL' => 137],
            ],
        ];
        $firstBatch = Product::whereIn('slug', array_keys($expected))->get();
        $this->assertCount(6, $firstBatch);
        $this->assertSame(628, Variant::whereIn('product_id', $firstBatch->modelKeys())->count());

        foreach ($expected as $slug => $groups) {
            $product = Product::where('slug', $slug)->firstOrFail();
            foreach ($groups as $group => $sizes) {
                foreach ($sizes as $size => $reseller) {
                    $variants = $product->variants()->where('color_group', $group)->where('size', (string) $size)->get();
                    $this->assertEqualsCanonicalizing($group === 'WHITE' ? ['White'] : $colors, $variants->pluck('color')->all(), $slug.' '.$group.' '.$size);
                    $this->assertSame([[$reseller * 100, ($reseller + 5) * 100, ($reseller + 15) * 100]],
                        $variants->map(fn ($v) => [$v->reseller_price, $v->wholesale_price, $v->retail_price])->unique()->values()->all());
                }
            }
        }
    }

    public function test_reference_charts_preserve_half_inches_and_unconfirmed_kids_sizes(): void
    {
        app(CatalogBatch::class)->import();
        $dannon = collect(Product::where('slug', 'dannon-with-collar')->firstOrFail()->size_chart)->keyBy('size');
        $this->assertEquals(['size' => '5XL', 'width' => 26.5, 'length' => 34], $dannon['5XL']);
        $this->assertEquals(['size' => 'S', 'width' => 18, 'length' => 26], $dannon['S']);
        $hanna = collect(Product::where('slug', 'lucky-hanna-with-collar')->firstOrFail()->size_chart)->keyBy('size');
        $this->assertEquals(['size' => '2XL', 'width' => 20.5, 'length' => 28], $hanna['2XL']);
        $this->assertEquals(['size' => '5XL', 'width' => 23.5, 'length' => 31], $hanna['5XL']);
        $adult = collect(Product::where('slug', 'dannon-round-neck-v-neck')->firstOrFail()->size_chart)->keyBy('size');
        $this->assertEquals(['size' => '4XL', 'width' => 24, 'length' => 31], $adult['4XL']);
        $kids = Product::where('slug', 'dannon-round-neck-v-neck-kids')->firstOrFail();
        $chart = collect($kids->size_chart)->keyBy('size');
        $this->assertEquals(['size' => '10', 'width' => 12, 'length' => 16], $chart['10']);
        $this->assertEquals(['size' => '12', 'width' => 13, 'length' => 17], $chart['12']);
        $this->assertEquals(['size' => '14', 'width' => 14, 'length' => 18], $chart['14']);
        $this->assertEquals(['size' => '16', 'width' => 15, 'length' => 19], $chart['16']);
        $this->assertEquals(['size' => '18', 'width' => 16, 'length' => 20], $chart['18']);
        $this->assertEquals(['size' => '20', 'width' => 17, 'length' => 21], $chart['20']);
        $this->assertSame(0, $kids->variants()->whereIn('size', ['14', '16', '18', '20'])->count());
        foreach (['4', '5', '6', '8', '9', '11'] as $size) {
            $this->assertNull($chart[$size]['width']);
            $this->assertNull($chart[$size]['length']);
        }
        $this->assertStringContainsString('await confirmation', $kids->size_chart_note);
    }

    public function test_import_archives_samples_and_is_idempotent_without_overwriting_owner_data(): void
    {
        $legacy = Product::create(['name' => 'Everyday Cotton Tee', 'slug' => 'everyday-cotton-tee', 'category' => '', 'description' => 'Original demo']);
        $legacyVariant = $legacy->variants()->create(['sku' => 'OLD-TEE', 'size' => 'M', 'color' => 'Black', 'stock' => 10, 'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
        $order = app(OrderFlow::class)->reserve([$legacyVariant->id => 1], ['recipient' => 'Buyer', 'phone' => '09123456789', 'address' => 'Pickup', 'delivery_method' => 'pickup', 'shipping' => 0], User::factory()->create()->id);
        $custom = Product::create(['name' => 'Owner Product', 'slug' => 'owner-product', 'category' => '', 'description' => 'Keep this']);

        $this->assertSame(1, app(CatalogBatch::class)->import()['samples_archived']);
        $this->assertFalse($legacy->fresh()->active);
        $this->assertTrue($custom->fresh()->active);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'variant_id' => $legacyVariant->id]);
        $this->assertSame(1, $legacyVariant->fresh()->reserved);

        $product = Product::where('slug', 'thanos')->firstOrFail();
        $product->update(['name' => 'Owner edited name', 'active' => false, 'image_path' => 'uploads/product.png', 'size_chart' => [['size' => 'XS', 'width' => 18, 'length' => 24]]]);
        $variant = $product->variants()->firstOrFail();
        $variant->update(['stock' => 42, 'retail_price' => 99900, 'image_path' => 'uploads/variant.png']);
        $this->assertSame(['products_created' => 0, 'variants_created' => 0, 'samples_archived' => 0], app(CatalogBatch::class)->import());
        $this->assertSame('Owner edited name', $product->fresh()->name);
        $this->assertFalse($product->fresh()->active);
        $this->assertSame('uploads/product.png', $product->fresh()->image_path);
        $this->assertEquals(18, $product->fresh()->size_chart[0]['width']);
        $this->assertSame(42, $variant->fresh()->stock);
        $this->assertSame(99900, $variant->fresh()->retail_price);
        $this->assertSame('uploads/variant.png', $variant->fresh()->image_path);
    }

    public function test_skus_are_generated_for_named_colors_and_import_command_is_available(): void
    {
        $this->artisan('shop:import-catalog')->assertSuccessful();
        $product = Product::where('slug', 'thanos')->firstOrFail();
        $lightBlue = $product->variants()->where('size', 'M')->where('color', 'Light Blue')->firstOrFail();
        $this->assertSame(Sku::forVariant($product, 'M', 'Light Blue'), $lightBlue->sku);
        $this->assertStringContainsString('-M-LIGHT-BLUE-', $lightBlue->sku);
        $this->assertNotSame(Sku::forVariant($product, 'M', 'Sky Blue'), Sku::forVariant($product, 'M', 'Sky-Blue'));
        $this->artisan('shop:import-catalog')->assertSuccessful();
        $this->assertDatabaseCount('variants', 1049);
    }

    public function test_import_preserves_owner_created_variant_with_a_custom_sku(): void
    {
        $product = Product::create(['name' => 'Owner title', 'slug' => 'thanos', 'category' => '', 'description' => 'Owner details']);
        $variant = $product->variants()->create([
            'sku' => 'OWNER-THANOS-XS-WHITE', 'size' => 'XS', 'color' => 'White', 'color_group' => 'WHITE',
            'stock' => 37, 'reserved' => 2, 'retail_price' => 25000, 'wholesale_price' => 22000, 'reseller_price' => 20000,
            'image_path' => 'uploads/owner-white.png',
        ]);
        $before = $variant->fresh()->getAttributes();

        $this->assertSame(['products_created' => 17, 'variants_created' => 1048, 'samples_archived' => 0], app(CatalogBatch::class)->import());

        $this->assertSame($before, $variant->fresh()->getAttributes());
        $this->assertSame('Owner title', $product->fresh()->name);
        $this->assertSame('Owner details', $product->fresh()->description);
        $this->assertSame(1, $product->variants()->where('size', 'XS')->where('color', 'White')->count());
        $this->assertSame(['products_created' => 0, 'variants_created' => 0, 'samples_archived' => 0], app(CatalogBatch::class)->import());
        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    public function test_import_preserves_renamed_imported_variant_and_its_original_sku(): void
    {
        app(CatalogBatch::class)->import();
        $product = Product::where('slug', 'thanos')->firstOrFail();
        $variant = $product->variants()->where('size', 'XS')->where('color', 'White')->firstOrFail();
        $originalSku = $variant->sku;
        $variant->update(['size' => 'Owner XS', 'color' => 'Pearl', 'stock' => 21, 'retail_price' => 30000]);

        $this->assertSame(['products_created' => 0, 'variants_created' => 0, 'samples_archived' => 0], app(CatalogBatch::class)->import());

        $this->assertSame($originalSku, $variant->fresh()->sku);
        $this->assertSame('Owner XS', $variant->fresh()->size);
        $this->assertSame('Pearl', $variant->fresh()->color);
        $this->assertSame(21, $variant->fresh()->stock);
        $this->assertSame(30000, $variant->fresh()->retail_price);
        $this->assertSame(0, $product->variants()->where('size', 'XS')->where('color', 'White')->count());
    }

    public function test_import_assigns_a_unique_sku_when_an_owner_variant_already_uses_the_generated_value(): void
    {
        $product = Product::create(['name' => 'Thanos', 'slug' => 'thanos', 'category' => '', 'description' => 'Existing product']);
        $ownerProduct = Product::create(['name' => 'Owner Product', 'slug' => 'owner-product', 'category' => '', 'description' => 'Keep this']);
        $occupiedSku = Sku::forVariant($product, 'XS', 'White');
        $ownerVariant = $ownerProduct->variants()->create([
            'sku' => $occupiedSku, 'size' => 'M', 'color' => 'Owner Blue', 'stock' => 17,
            'retail_price' => 20000, 'wholesale_price' => 18000, 'reseller_price' => 16000,
        ]);

        $this->assertSame(['products_created' => 17, 'variants_created' => 1049, 'samples_archived' => 0], app(CatalogBatch::class)->import());

        $newVariant = $product->variants()->where('size', 'XS')->where('color', 'White')->firstOrFail();
        $this->assertSame($occupiedSku.'-2', $newVariant->sku);
        $this->assertSame($occupiedSku, $ownerVariant->fresh()->sku);
        $this->assertSame(17, $ownerVariant->fresh()->stock);
        $this->assertSame(0, $newVariant->stock);
        $this->assertSame(['products_created' => 0, 'variants_created' => 0, 'samples_archived' => 0], app(CatalogBatch::class)->import());
        $this->assertSame($newVariant->id, $product->variants()->where('size', 'XS')->where('color', 'White')->firstOrFail()->id);
    }
}
