<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\CatalogBatch;
use App\Services\OrderFlow;
use App\Services\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_permanent_catalog_appends_twelve_styles_without_replacing_the_first_six(): void
    {
        $this->assertSame(['products_created' => 18, 'variants_created' => 1049, 'samples_archived' => 0], app(CatalogBatch::class)->import());
        $this->assertDatabaseCount('products', 18);
        $this->assertDatabaseCount('variants', 1049);
        $this->assertSame(1049, Variant::distinct()->count('sku'));
        $this->assertSame(0, (int) Variant::sum('stock'));
        $this->assertSame(0, (int) Variant::sum('reserved'));

        foreach (['dannon-round-neck-v-neck' => 126, 'dannon-with-collar' => 110, 'lucky-hanna-v-neck' => 84, 'lucky-hanna-with-collar' => 112, 'dannon-round-neck-v-neck-kids' => 112, 'thanos' => 84] as $slug => $count) {
            $product = Product::where('slug', $slug)->firstOrFail();
            $this->assertTrue($product->active, $slug);
            $this->assertSame($count, $product->variants()->count(), $slug);
        }

        foreach (self::expectedExpansion() as $slug => $expected) {
            $product = Product::where('slug', $slug)->firstOrFail();
            $this->assertSame($expected['name'], $product->name, $slug);
            $this->assertTrue($product->active, $slug);
            $expectedVariants = [];
            foreach ($expected['groups'] as $group => $sizes) {
                $colors = match ($group) {
                    'white' => ['White'],
                    'light' => ['Light Green', 'Light Red', 'Light Yellow'],
                    'dark' => ['Dark Green', 'Dark Red', 'Dark Yellow'],
                    'colored' => ['Green', 'Red', 'Yellow'],
                    default => [ucfirst($group)],
                };
                foreach ($sizes as $size => [$wholesale, $retail]) {
                    foreach ($colors as $color) {
                        $expectedVariants[$color.'|'.$size] = [$group === 'white' ? 'WHITE' : 'COLORED', ($retail - 20) * 100, $wholesale * 100, $retail * 100];
                    }
                }
            }
            $actual = $product->variants()->get()->mapWithKeys(fn ($variant) => [$variant->color.'|'.$variant->size => [$variant->color_group, $variant->reseller_price, $variant->wholesale_price, $variant->retail_price]])->all();
            ksort($expectedVariants);
            ksort($actual);
            $this->assertSame($expectedVariants, $actual, $slug.' must exactly match the owner price list, including unusual prices and literal size labels.');
            $this->assertSame(count($expectedVariants), $product->variants()->count(), $slug.' must not contain duplicate size/color pairs.');
        }
    }

    public function test_reimport_preserves_owner_edits_inventory_and_original_order_snapshots(): void
    {
        app(CatalogBatch::class)->import();
        $original = Product::where('slug', 'thanos')->firstOrFail()->variants()->where('size', 'XS')->where('color', 'White')->firstOrFail();
        $newProduct = Product::where('slug', 'yalex-round-neck')->firstOrFail();
        $new = $newProduct->variants()->where('size', '2X')->where('color', 'Dark Red')->firstOrFail();
        $original->update(['stock' => 15]);
        $new->update(['stock' => 24]);
        $order = app(OrderFlow::class)->reserve([$original->id => 2, $new->id => 3], [
            'recipient' => 'Catalog regression buyer', 'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0,
        ], User::factory()->create()->id);
        $items = $order->items()->orderBy('id')->get()->toArray();
        $invoice = $order->fresh()->toArray();

        $newProduct->update(['name' => 'Owner renamed Yalex', 'active' => false, 'image_path' => 'uploads/products/owner.png', 'size_chart' => [['size' => '2X', 'width' => 22.5, 'length' => 30]]]);
        $new->update(['retail_price' => 19900, 'wholesale_price' => 17900, 'reseller_price' => 15900, 'image_path' => 'uploads/variants/owner.png']);
        $original->update(['reorder_level' => 8, 'retail_price' => 15100]);
        $beforeProduct = $newProduct->fresh()->toArray();
        $beforeNew = $new->fresh()->toArray();
        $beforeOriginal = $original->fresh()->toArray();

        foreach (range(1, 2) as $attempt) {
            $this->assertSame(['products_created' => 0, 'variants_created' => 0, 'samples_archived' => 0], app(CatalogBatch::class)->import());
        }
        $this->assertSame($beforeProduct, $newProduct->fresh()->toArray());
        $this->assertSame($beforeNew, $new->fresh()->toArray());
        $this->assertSame($beforeOriginal, $original->fresh()->toArray());
        $this->assertSame(3, $new->fresh()->reserved);
        $this->assertSame(2, $original->fresh()->reserved);
        $this->assertSame($items, $order->items()->orderBy('id')->get()->toArray());
        $this->assertSame($invoice, $order->fresh()->toArray());
        $this->assertDatabaseCount('products', 18);
        $this->assertDatabaseCount('variants', 1049);
    }

    public function test_new_prices_use_total_mixed_cart_quantity_and_bulk_discounts_the_reseller_subtotal(): void
    {
        app(CatalogBatch::class)->import();
        $yalex = Product::where('slug', 'yalex-round-neck')->firstOrFail()->variants()->where('color', 'White')->where('size', 'XS')->firstOrFail();
        $apple = Product::where('slug', 'apple-tees-dri-fit')->firstOrFail()->variants()->where('color', 'Reflector')->where('size', 'Free Size')->firstOrFail();
        $pricing = app(Pricing::class);
        foreach ([1 => ['retail', 10000], 6 => ['wholesale', 9000], 51 => ['reseller', 8000], 100 => ['reseller', 8000], 101 => ['bulk', 8000]] as $quantity => [$tier, $unitPrice]) {
            $cart = $pricing->cart([$yalex->id => $quantity]);
            $this->assertSame($tier, $cart['tier']);
            $this->assertSame($unitPrice * $quantity, $cart['base']);
            $this->assertSame($quantity === 101 ? 40400 : 0, $cart['discount']);
            $this->assertSame($unitPrice * $quantity - ($quantity === 101 ? 40400 : 0), $cart['subtotal']);
        }
        $mixed = $pricing->cart([$yalex->id => 25, $apple->id => 26]);
        $this->assertSame('reseller', $mixed['tier']);
        $this->assertSame(577000, $mixed['subtotal']);
        $this->assertSame([8000, 14500], array_column($mixed['lines'], 'price'));
        $mixedBulk = $pricing->cart([$yalex->id => 51, $apple->id => 50]);
        $this->assertSame('bulk', $mixedBulk['tier']);
        $this->assertSame(1133000, $mixedBulk['base']);
        $this->assertSame(56650, $mixedBulk['discount']);
        $this->assertSame(1076350, $mixedBulk['subtotal']);
    }

    public function test_literal_new_sizes_and_finish_options_are_filterable_and_can_be_added_to_cart(): void
    {
        app(CatalogBatch::class)->import();
        foreach ([
            ['yalex-round-neck', '5X', 'Dark Yellow'],
            ['yalex-kids', '#10', 'Light Green'],
            ['softex-round-neck', 'TN', 'Light Red'],
            ['apple-tees-dri-fit', 'Free Size', 'Plain'],
            ['apple-tees-dri-fit', 'Free Size', 'Reflector'],
        ] as [$slug, $size, $color]) {
            $product = Product::where('slug', $slug)->firstOrFail();
            $variant = $product->variants()->where('size', $size)->where('color', $color)->firstOrFail();
            $this->get('/?'.http_build_query(['size' => $size, 'color' => $color]))->assertOk()
                ->assertSee($product->name)->assertViewHas('products', fn ($products) => $products->contains('id', $product->id));
            $this->get(route('product', $product))->assertOk()->assertSee($size)->assertSee($color);
            $variant->update(['stock' => 2]);
            $this->withSession(['cart' => []])->post(route('cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])
                ->assertRedirect(route('cart'))->assertSessionHasNoErrors()->assertSessionHas('cart', [$variant->id => 1]);
            $this->get(route('cart'))->assertOk()->assertSee($size.' / '.$color);
        }
    }

    public function test_storefront_orders_literal_size_aliases_naturally_and_free_size_product_has_only_real_choices(): void
    {
        app(CatalogBatch::class)->import();
        $sizes = $this->get('/')->assertOk()->viewData('sizes')->all();
        $adult = ['TN', 'XS', 'S', 'M', 'L', 'XL', '2X', '2XL', '3X', '3XL', '4XL', '5X', '5XL', 'Free Size'];
        $kids = ['#4', '#6', '#8', '#10', '#12', '#14', '#16', '#18', '#20'];
        $this->assertSame($adult, array_values(array_intersect($sizes, $adult)));
        $this->assertSame($kids, array_values(array_intersect($sizes, $kids)));

        foreach ([
            'yalex-round-neck' => ['XS', 'S', 'M', 'L', 'XL', '2X', '3X', '5X'],
            'yalex-kids' => ['#10', '#12', '#14', '#16', '#18', '#20'],
            'softex-round-neck' => ['TN', 'XS', 'S', 'M', 'L', 'XL', '2X'],
        ] as $slug => $expectedSizes) {
            $product = Product::where('slug', $slug)->firstOrFail();
            $response = $this->get(route('product', $product))->assertOk();
            preg_match('/<script type="application\/json" id="product-variants">(.*?)<\/script>/s', $response->getContent(), $matches);
            $this->assertArrayHasKey(1, $matches);
            $variants = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            $whiteSizes = array_column(array_values(array_filter($variants, fn ($variant) => $variant['color'] === 'White')), 'size');
            $this->assertSame($expectedSizes, $whiteSizes, $slug.' must present size choices in natural order.');
        }

        $finishProduct = Product::where('slug', 'apple-tees-dri-fit')->firstOrFail();
        $response = $this->get(route('product', $finishProduct))->assertOk()
            ->assertSee('CHOOSE YOUR STYLE AND SIZE')->assertSee('>Style<select', false)
            ->assertDontSee('<option>WHITE</option>', false)->assertDontSee('WHITE + COLORED');
        $this->assertSame(['Plain', 'Reflector'], $response->viewData('product')->variants->pluck('color')->all());
        $this->assertSame(['COLORED'], $response->viewData('product')->variants->pluck('color_group')->unique()->all());
        $this->get('/?'.http_build_query(['q' => 'APPLE TEES DRI-FIT', 'size' => 'Free Size']))->assertOk()
            ->assertSee('PLAIN + REFLECTOR')->assertSee('2 styles')->assertDontSee('WHITE + COLORED');
    }

    /** Prices independently transcribed from the owner's second-batch list: wholesale, retail in pesos. */
    private static function expectedExpansion(): array
    {
        return [
            'yalex-round-neck' => ['name' => 'YALEX - Round Neck', 'groups' => [
                'white' => ['XS' => [90, 100], 'S' => [95, 105], 'M' => [100, 110], 'L' => [105, 115], 'XL' => [109, 119], '2X' => [114, 124], '3X' => [118, 128], '5X' => [129, 139]],
                'light' => ['XS' => [95, 105], 'S' => [101, 111], 'M' => [106, 116], 'L' => [111, 121], 'XL' => [115, 125], '2X' => [120, 130], '3X' => [124, 134], '5X' => [135, 145]],
                'dark' => ['XS' => [102, 112], 'S' => [107, 117], 'M' => [112, 122], 'L' => [117, 127], 'XL' => [121, 131], '2X' => [126, 136], '3X' => [130, 140], '5X' => [141, 151]],
            ]],
            'yalex-kids' => ['name' => 'YALEX - Kids', 'groups' => [
                'white' => ['#10' => [66, 76], '#12' => [69, 79], '#14' => [72, 82], '#16' => [74, 84], '#18' => [77, 87], '#20' => [79, 89]],
                'light' => ['#10' => [71, 81], '#12' => [74, 84], '#14' => [77, 87], '#16' => [79, 89], '#18' => [82, 92], '#20' => [84, 94]],
                'dark' => ['#10' => [76, 86], '#12' => [79, 89], '#14' => [82, 92], '#16' => [84, 94], '#18' => [87, 97], '#20' => [89, 99]],
            ]],
            'yalex-v-neck' => ['name' => 'YALEX - V-Neck', 'groups' => [
                'white' => ['XS' => [94, 104], 'S' => [98, 108], 'M' => [103, 113], 'L' => [108, 118], 'XL' => [112, 122], '2X' => [117, 127]],
                'light' => ['XS' => [99, 109], 'S' => [103, 113], 'M' => [108, 118], 'L' => [113, 123], 'XL' => [117, 127], '2X' => [122, 132]],
                'dark' => ['XS' => [106, 116], 'S' => [110, 120], 'M' => [115, 125], 'L' => [120, 130], 'XL' => [124, 134], '2X' => [129, 139]],
            ]],
            'yalex-with-collar' => ['name' => 'YALEX - With Collar', 'groups' => [
                'white' => ['XS' => [107, 117], 'S' => [111, 121], 'M' => [116, 126], 'L' => [120, 130], 'XL' => [125, 135], '2X' => [129, 139]],
                'light' => ['XS' => [113, 123], 'S' => [117, 127], 'M' => [122, 132], 'L' => [126, 136], 'XL' => [131, 141], '2X' => [135, 145]],
                'dark' => ['XS' => [119, 129], 'S' => [123, 132], 'M' => [128, 136], 'L' => [132, 142], 'XL' => [137, 147], '2X' => [141, 151]],
            ]],
            'softex-round-neck' => ['name' => 'SOFTEX - Round Neck', 'groups' => [
                'white' => ['TN' => [80, 90], 'XS' => [84, 94], 'S' => [89, 99], 'M' => [94, 104], 'L' => [99, 109], 'XL' => [104, 114], '2X' => [109, 119]],
                'light' => ['TN' => [86, 96], 'XS' => [90, 100], 'S' => [95, 105], 'M' => [100, 110], 'L' => [105, 115], 'XL' => [110, 120], '2X' => [115, 125]],
                'dark' => ['TN' => [92, 102], 'XS' => [96, 106], 'S' => [101, 111], 'M' => [106, 116], 'L' => [111, 121], 'XL' => [116, 126], '2X' => [121, 131]],
            ]],
            'softex-kids' => ['name' => 'SOFTEX - Kids', 'groups' => [
                'white' => ['#4' => [57, 67], '#6' => [60, 70], '#8' => [63, 73], '#10' => [66, 76], '#12' => [69, 79], '#14' => [72, 82]],
                'light' => ['#4' => [62, 72], '#6' => [65, 75], '#8' => [68, 78], '#10' => [71, 81], '#12' => [74, 84], '#14' => [77, 87]],
                'dark' => ['#4' => [66, 76], '#6' => [69, 79], '#8' => [72, 82], '#10' => [75, 85], '#12' => [78, 88], '#14' => [81, 91]],
            ]],
            'softex-with-collar' => ['name' => 'SOFTEX - With Collar', 'groups' => [
                'white' => ['TN' => [98, 108], 'XS' => [102, 112], 'S' => [107, 117], 'M' => [112, 122], 'L' => [117, 127], 'XL' => [122, 132], '2X' => [127, 137]],
                'light' => ['TN' => [104, 114], 'XS' => [108, 118], 'S' => [113, 123], 'M' => [118, 128], 'L' => [123, 133], 'XL' => [128, 138], '2X' => [133, 143]],
                'dark' => ['TN' => [110, 120], 'XS' => [114, 124], 'S' => [119, 129], 'M' => [124, 134], 'L' => [129, 139], 'XL' => [134, 144], '2X' => [139, 149]],
            ]],
            'softex-long-sleeve' => ['name' => 'SOFTEX - Long Sleeve', 'groups' => [
                'white' => ['M' => [117, 127], 'L' => [124, 134], 'XL' => [131, 141]],
                'light' => ['M' => [126, 136], 'L' => [133, 143], 'XL' => [140, 150]],
                'dark' => ['M' => [134, 144], 'L' => [141, 151], 'XL' => [148, 158]],
            ]],
            'flipper' => ['name' => 'FLIPPER', 'groups' => [
                'white' => ['S' => [105, 125], 'M' => [112, 122], 'L' => [119, 129], 'XL' => [126, 136]],
                'light' => ['S' => [113, 123], 'M' => [120, 130], 'L' => [127, 137], 'XL' => [134, 144]],
                'dark' => ['S' => [121, 131], 'M' => [128, 138], 'L' => [135, 145], 'XL' => [142, 152]],
            ]],
            'apple-tees-dri-fit-round-neck' => ['name' => 'APPLE TEES DRI-FIT - Round Neck', 'groups' => [
                'white' => ['XS' => [83, 93], 'S' => [83, 93], 'M' => [83, 93], 'L' => [83, 93], 'XL' => [88, 98], '2X' => [88, 98]],
                'colored' => ['XS' => [94, 104], 'S' => [94, 104], 'M' => [94, 104], 'L' => [94, 104], 'XL' => [99, 109], '2X' => [99, 109]],
            ]],
            'apple-tees-dri-fit-with-collar' => ['name' => 'APPLE TEES DRI-FIT - With Collar', 'groups' => [
                'white' => ['XS' => [145, 155], 'S' => [145, 155], 'M' => [145, 155], 'L' => [145, 155], 'XL' => [150, 160], '2X' => [150, 160]],
                'colored' => ['XS' => [160, 170], 'S' => [160, 170], 'M' => [160, 170], 'L' => [160, 170], 'XL' => [165, 175], '2X' => [165, 175]],
            ]],
            'apple-tees-dri-fit' => ['name' => 'APPLE TEES DRI-FIT', 'groups' => [
                'plain' => ['Free Size' => [90, 100]],
                'reflector' => ['Free Size' => [155, 165]],
            ]],
        ];
    }
}
