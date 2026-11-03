<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\CatalogBatch;
use App\Services\DannonNecklineSplit;
use App\Services\ImageUploads;
use App\Services\OrderFlow;
use App\Services\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DannonNecklineSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function original(bool $kids = false): Product
    {
        $product = Product::create([
            'slug' => 'dannon-round-neck-v-neck'.($kids ? '-kids' : ''),
            'name' => 'DANNON - ROUND NECK / V-NECK'.($kids ? ' KIDS' : ''),
            'category' => '', 'description' => 'Owner-confirmed clothing details.',
            'active' => true, 'image_path' => 'uploads/products/shared.jpg',
            'size_chart' => [['size' => 'M', 'width' => 19.5, 'length' => 27], ['size' => 'L', 'width' => null, 'length' => null]],
            'size_chart_note' => 'Owner measurements; missing values await confirmation.',
        ]);
        foreach (['White', 'Red'] as $color) {
            $product->variants()->create([
                'sku' => Sku::forVariant($product, 'M', $color), 'size' => 'M', 'color' => $color,
                'color_group' => $color === 'White' ? 'WHITE' : 'COLORED',
                'stock' => $color === 'White' ? 41 : 29, 'reserved' => 0, 'reorder_level' => 7,
                'image_path' => 'uploads/variants/shared.jpg',
                'retail_price' => $color === 'White' ? 19900 : 21900,
                'wholesale_price' => $color === 'White' ? 18900 : 20900,
                'reseller_price' => $color === 'White' ? 17900 : 19900,
            ]);
        }

        return $product;
    }

    public function test_both_existing_styles_split_without_moving_stock_reservations_or_order_history(): void
    {
        $adult = $this->original();
        $kids = $this->original(true);
        $variant = $adult->variants()->where('color', 'White')->firstOrFail();
        $order = app(OrderFlow::class)->reserve([$variant->id => 2], [
            'recipient' => 'Existing buyer', 'phone' => '09123456789', 'address' => 'Store pickup',
            'delivery_method' => 'pickup', 'shipping' => 0,
        ], User::factory()->create()->id);
        $beforeOrder = $order->fresh()->getAttributes();
        $beforeItems = $order->items()->get()->toArray();
        $beforeVariants = Variant::orderBy('id')->get()->mapWithKeys(fn ($row) => [$row->id => $row->getAttributes()])->all();

        $this->assertSame(['products_created' => 2, 'variants_created' => 4], app(DannonNecklineSplit::class)->apply());
        $this->assertSame('DANNON - ROUND NECK', $adult->fresh()->name);
        $this->assertSame('DANNON - ROUND NECK KIDS', $kids->fresh()->name);
        foreach ($beforeVariants as $id => $attributes) {
            $this->assertSame($attributes, Variant::findOrFail($id)->getAttributes());
        }
        $this->assertSame($beforeOrder, $order->fresh()->getAttributes());
        $this->assertSame($beforeItems, $order->items()->get()->toArray());
        $this->assertSame(2, $variant->fresh()->reserved);
        foreach (['dannon-v-neck', 'dannon-v-neck-kids'] as $slug) {
            $target = Product::where('slug', $slug)->firstOrFail();
            $this->assertSame(0, (int) $target->variants()->sum('stock'));
            $this->assertSame(0, (int) $target->variants()->sum('reserved'));
            $this->assertDatabaseMissing('order_items', ['variant_id' => $target->variants()->firstOrFail()->id]);
        }
        Http::assertNothingSent();
    }

    public function test_owner_prices_images_cast_charts_visibility_and_custom_variants_are_copied_once(): void
    {
        $source = $this->original();
        $source->update(['active' => false]);
        $source->variants()->create(['sku' => 'OWNER-CUSTOM', 'size' => 'Owner size', 'color' => 'Pearl', 'stock' => 9,
            'reserved' => 1, 'reorder_level' => 3, 'retail_price' => 33000, 'wholesale_price' => 32000, 'reseller_price' => 31000]);
        app(DannonNecklineSplit::class)->apply();
        $target = Product::where('slug', 'dannon-v-neck')->firstOrFail();
        $this->assertSame($source->fresh()->size_chart, $target->size_chart);
        $this->assertIsArray($target->size_chart);
        $this->assertSame(19.5, $target->size_chart[0]['width']);
        foreach (['description', 'image_path', 'image_url', 'size_chart_note', 'active', 'art_color'] as $field) {
            $this->assertSame($source->fresh()->getAttribute($field), $target->getAttribute($field), $field);
        }
        foreach ($source->variants as $variant) {
            $copy = $target->variants()->where('size', $variant->size)->where('color', $variant->color)->firstOrFail();
            foreach (['retail_price', 'wholesale_price', 'reseller_price', 'image_path', 'reorder_level'] as $field) {
                $this->assertSame($variant->getAttribute($field), $copy->getAttribute($field), $field);
            }
            $this->assertNotSame($variant->sku, $copy->sku);
            $this->assertSame(0, $copy->stock);
            $this->assertSame(0, $copy->reserved);
        }
        $copy = $target->variants()->firstOrFail();
        $copy->update(['stock' => 12, 'retail_price' => 45000]);
        $before = $copy->fresh()->getAttributes();
        $source->update(['name' => 'Owner Round Neck title']);
        $this->assertSame(['products_created' => 0, 'variants_created' => 0], app(DannonNecklineSplit::class)->apply());
        $this->assertSame($before, $copy->fresh()->getAttributes());
        $this->assertSame('Owner Round Neck title', $source->fresh()->name);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('variants', 6);
    }

    public function test_a_conflicting_target_rolls_back_every_style_without_overwriting_the_other_product(): void
    {
        $adult = $this->original();
        $kids = $this->original(true);
        $occupied = Product::create(['name' => 'Independent owner product', 'slug' => 'dannon-v-neck-kids', 'category' => '', 'description' => 'Keep me']);
        $before = Product::orderBy('id')->get()->map->getAttributes()->all();
        try {
            app(DannonNecklineSplit::class)->apply();
            $this->fail('An occupied neckline must require owner review.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('already belongs to another product', $exception->getMessage());
        }
        $this->assertSame($before, Product::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertDatabaseMissing('products', ['slug' => 'dannon-v-neck']);
        $this->assertDatabaseCount('variants', 4);
        $this->assertSame(0, DB::table('shop_settings')->where('key', 'like', 'catalog.dannon_neckline_split.%')->count());
    }

    public function test_fresh_catalog_has_equal_neckline_prices_and_leaves_lucky_hanna_separate(): void
    {
        $this->assertSame(['products_created' => 20, 'variants_created' => 1287, 'samples_archived' => 0], app(CatalogBatch::class)->import());
        foreach (['dannon-round-neck-v-neck' => 'dannon-v-neck', 'dannon-round-neck-v-neck-kids' => 'dannon-v-neck-kids'] as $sourceSlug => $targetSlug) {
            $source = Product::where('slug', $sourceSlug)->firstOrFail();
            $target = Product::where('slug', $targetSlug)->firstOrFail();
            $columns = ['size', 'color', 'retail_price', 'wholesale_price', 'reseller_price'];
            $this->assertSame($source->variants()->orderBy('id')->get($columns)->toArray(), $target->variants()->orderBy('id')->get($columns)->toArray());
            $this->assertSame($source->size_chart, $target->size_chart);
        }
        $hanna = Product::whereIn('slug', ['lucky-hanna-v-neck', 'lucky-hanna-with-collar'])->orderBy('id')->get()->map->getAttributes()->all();
        $this->assertSame(['products_created' => 0, 'variants_created' => 0], app(DannonNecklineSplit::class)->apply());
        $this->assertSame($hanna, Product::whereIn('slug', ['lucky-hanna-v-neck', 'lucky-hanna-with-collar'])->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('products', 20);
        $this->assertDatabaseCount('variants', 1287);
    }

    public function test_import_clones_original_owner_labels_before_catalog_fills_and_stable_skus_prevent_duplicate_renamed_variants(): void
    {
        $source = $this->original();
        $variant = $source->variants()->where('color', 'White')->firstOrFail();
        $variant->update(['size' => 'Owner M', 'color' => 'Pearl', 'retail_price' => 33300]);
        app(CatalogBatch::class)->import();
        $target = Product::where('slug', 'dannon-v-neck')->firstOrFail();
        $copy = $target->variants()->where('size', 'Owner M')->where('color', 'Pearl')->sole();
        $this->assertSame(Sku::forVariant($target, 'M', 'White'), $copy->sku);
        $this->assertSame(33300, $copy->retail_price);
        $this->assertSame(0, $target->variants()->where('size', 'M')->where('color', 'White')->count());
        $before = $copy->getAttributes();
        $this->assertSame(['products_created' => 0, 'variants_created' => 0, 'samples_archived' => 0], app(CatalogBatch::class)->import());
        $this->assertSame($before, $copy->fresh()->getAttributes());
    }

    public function test_shared_images_are_not_deleted_when_the_round_neck_product_or_variant_changes_photo(): void
    {
        Storage::fake('dannon-split-uploads');
        Storage::set('uploads', Storage::disk('dannon-split-uploads'));
        Storage::disk('uploads')->put('products/shared.jpg', 'product picture');
        Storage::disk('uploads')->put('variants/shared.jpg', 'variant picture');
        $source = $this->original();
        app(DannonNecklineSplit::class)->apply();
        $source->update(['image_path' => null]);
        $source->variants()->update(['image_path' => null]);
        app(ImageUploads::class)->delete('uploads/products/shared.jpg');
        app(ImageUploads::class)->delete('uploads/variants/shared.jpg');
        Storage::disk('uploads')->assertExists('products/shared.jpg');
        Storage::disk('uploads')->assertExists('variants/shared.jpg');
    }

    public function test_split_command_is_available_and_migration_is_safe_to_repeat_without_legacy_products(): void
    {
        $this->artisan('shop:split-dannon-necklines')->assertSuccessful();
        $migration = require database_path('migrations/2026_11_02_000026_split_dannon_necklines.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('variants', 0);
    }
}
