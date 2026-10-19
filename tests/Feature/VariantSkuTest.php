<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\CatalogBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariantSkuTest extends TestCase
{
    use RefreshDatabase;

    public function test_renaming_an_imported_variant_preserves_its_sku_and_allows_reusing_the_original_color(): void
    {
        app(CatalogBatch::class)->import();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $product = Product::where('slug', 'thanos')->firstOrFail();
        $variant = $product->variants()->where('size', 'M')->where('color', 'Red')->firstOrFail();
        $originalSku = $variant->sku;
        $fields = ['size' => 'M', 'color_group' => 'COLORED', 'color' => 'Custom Coral', 'stock' => 0, 'expected_stock' => $variant->stock,
            'reorder_level' => 10, 'retail' => 129, 'wholesale' => 119, 'reseller' => 114];

        $this->patch(route('admin.variants.save', [$product, $variant]), $fields)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($originalSku, $variant->fresh()->sku);

        // Recreate the old color across all sizes after renaming its other variants as well.
        foreach ($product->variants()->where('color', 'Red')->get() as $other) {
            $other->update(['color' => 'Custom Coral']);
        }
        $this->post(route('admin.colors.store', $product), ['color' => 'Red'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $new = $product->variants()->where('size', 'M')->where('color', 'Red')->firstOrFail();
        $this->assertNotSame($originalSku, $new->sku);
        $this->assertSame(6, $product->variants()->where('color', 'Red')->count());
        $this->assertSame(0, app(CatalogBatch::class)->import()['variants_created']);
        $this->assertSame('Custom Coral', $variant->fresh()->color);
        $this->assertSame($new->sku, $new->fresh()->sku);
    }

    public function test_single_variant_creation_assigns_a_unique_sku_after_renaming_an_existing_variant(): void
    {
        app(CatalogBatch::class)->import();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $product = Product::where('slug', 'thanos')->firstOrFail();
        $old = $product->variants()->where('size', 'M')->where('color', 'Red')->firstOrFail();
        $old->update(['color' => 'Custom Coral']);
        $this->post(route('admin.variants.store', $product), ['size' => 'M', 'color_group' => 'COLORED',
            'color' => 'Red', 'stock' => 0, 'reorder_level' => 10, 'retail' => 129, 'wholesale' => 119, 'reseller' => 114])
            ->assertRedirect()->assertSessionHasNoErrors();
        $new = $product->variants()->where('size', 'M')->where('color', 'Red')->firstOrFail();
        $this->assertNotSame($old->sku, $new->sku);
    }
}
