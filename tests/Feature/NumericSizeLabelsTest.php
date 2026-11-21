<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\CatalogBatch;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use App\Services\Sku;
use App\Services\StoreKnowledge;
use App\Services\VariantSizes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NumericSizeLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function product(string $name = 'YALEX KIDS'): Product
    {
        return Product::create(['name' => $name, 'slug' => 'numeric-size-'.uniqid(), 'active' => true,
            'category' => '', 'description' => 'Numeric size fixture',
            'size_chart' => [['size' => '#10', 'width' => 16, 'length' => 22]]]);
    }

    private function variant(Product $product, string $size = '#10', string $color = 'White', int $price = 10000): Variant
    {
        return $product->variants()->create(['size' => $size, 'color' => $color,
            'sku' => Sku::forVariant($product, $size, $color), 'stock' => 20, 'reserved' => 0, 'reorder_level' => 5,
            'retail_price' => $price, 'wholesale_price' => $price - 1000, 'reseller_price' => $price - 2000])->fresh();
    }

    private function sizeOptions(string $html): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $options = [];
        foreach ((new \DOMXPath($document))->query('//select[@name="size"]/option') as $option) {
            if ($option->getAttribute('value') !== '') {
                $options[] = ['value' => $option->getAttribute('value'), 'label' => $option->textContent,
                    'selected' => $option->hasAttribute('selected')];
            }
        }

        return $options;
    }

    public function test_product_selector_keeps_the_original_variant_identity_and_price_behind_a_clean_size_label(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $before = $variant->getAttributes();
        $response = $this->get(route('product', $product))->assertOk()->assertSee('<td>10</td>', false);
        $this->assertSame(1, preg_match('/<script[^>]*id="product-variants"[^>]*>(.*?)<\/script>/s', $response->getContent(), $json));
        $data = json_decode($json[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($variant->id, $data[0]['id']);
        $this->assertSame('#10', $data[0]['size']);
        $this->assertSame('10', $data[0]['size_label']);
        $this->assertSame([20, 10000, 9000, 8000], [$data[0]['stock'], $data[0]['retail'], $data[0]['wholesale'], $data[0]['reseller']]);
        $this->assertSame($before, $variant->fresh()->getAttributes());
        $this->assertSame('#10', $product->fresh()->size_chart[0]['size']);
        $this->assertSame('Special #10', VariantSizes::label('Special #10'));
        $this->assertSame('#M', VariantSizes::label('#M'));
    }

    public function test_one_store_size_filter_finds_legacy_hash_and_plain_numeric_catalog_entries(): void
    {
        $legacy = $this->product();
        $plain = $this->product('DANNON KIDS');
        $this->variant($legacy);
        $this->variant($plain, '10');
        $this->variant($this->product('OTHER KIDS'), '#12');

        foreach (['10', '#10'] as $size) {
            $response = $this->get(route('store', ['size' => $size]))->assertOk()
                ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$legacy->id, $plain->id]);
            $options = $this->sizeOptions($response->getContent());
            $tens = array_values(array_filter($options, fn ($option) => $option['label'] === '10'));
            $this->assertCount(1, $tens);
            $this->assertTrue($tens[0]['selected']);
            $this->assertNotContains('#10', array_column($options, 'label'));
        }
        $this->assertSame(['#10', '10', '#12'], Variant::orderBy('id')->pluck('size')->all());
    }

    public function test_admin_size_options_do_not_duplicate_labels_and_preserve_legacy_values_on_edit_and_new_colors(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $this->variant($product, '10', 'Red');
        $this->variant($product, '14', 'White');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([[], ['variant' => $variant->id]] as $query) {
            $response = $this->get(route('admin.products.edit', [$product] + $query))->assertOk();
            $options = $this->sizeOptions($response->getContent());
            $this->assertCount(count(array_unique(array_column($options, 'label'))), $options);
            $ten = array_values(array_filter($options, fn ($option) => $option['label'] === '10'))[0];
            $this->assertSame('#10', $ten['value']);
            $this->assertSame($query !== [], $ten['selected']);
            $fourteen = array_values(array_filter($options, fn ($option) => $option['label'] === '14'))[0];
            $this->assertSame('14', $fourteen['value']);
        }

        $this->patch(route('admin.variants.save', [$product, $variant]), ['size' => '#10', 'color' => 'White',
            'stock' => 20, 'expected_stock' => 20, 'reorder_level' => 5, 'retail' => 100, 'wholesale' => 90, 'reseller' => 80])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['#10', $variant->sku, 20, 10000],
            [$variant->fresh()->size, $variant->fresh()->sku, $variant->fresh()->stock, $variant->fresh()->retail_price]);
    }

    public function test_numeric_alias_cannot_create_another_variant_with_the_same_color_even_with_a_forged_form(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $before = $variant->getAttributes();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.variants.store', $product), ['size' => '10', 'color' => 'white', 'stock' => 1,
                'reorder_level' => 5, 'retail' => 100, 'wholesale' => 90, 'reseller' => 80])->assertSessionHasErrors('color');
        $this->assertDatabaseCount('variants', 1);
        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    public function test_bulk_color_copy_does_not_multiply_legacy_numeric_aliases_or_change_the_source_stock(): void
    {
        $product = $this->product();
        $first = $this->variant($product);
        $second = $this->variant($product, '10', 'White', 12000);
        $before = [$first->getAttributes(), $second->getAttributes()];
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.colors.store', $product), ['color' => 'Blue', 'template_color' => 'White'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $blue = $product->variants()->where('color', 'Blue')->get();
        $this->assertCount(1, $blue);
        $this->assertSame(['#10', 0, 10000], [$blue[0]->size, $blue[0]->stock, $blue[0]->retail_price]);
        $this->assertSame($before, [$first->fresh()->getAttributes(), $second->fresh()->getAttributes()]);
    }

    public function test_chart_cannot_add_two_rows_with_the_same_visible_numeric_size(): void
    {
        $product = $this->product();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('admin.products.save', $product), ['name' => $product->name, 'description' => $product->description, 'active' => 1,
                'size_chart' => [['size' => '#10', 'width' => 16, 'length' => 22], ['size' => '10', 'width' => 18, 'length' => 24]]])
            ->assertSessionHasErrors('size_chart');
        $this->assertSame([['size' => '#10', 'width' => 16, 'length' => 22]], $product->fresh()->size_chart);
    }

    public function test_reimport_reuses_an_owner_numeric_variant_instead_of_creating_its_hash_alias(): void
    {
        $product = $this->product();
        $product->update(['slug' => 'yalex-kids']);
        $variant = $this->variant($product, '10', 'White', 19000);
        $variant->update(['sku' => 'OWNER-NUMERIC-TEN', 'stock' => 37, 'reserved' => 2]);
        $before = $variant->fresh()->getAttributes();

        app(CatalogBatch::class)->import();
        $this->assertSame(1, $product->variants()->whereIn('size', ['10', '#10'])->where('color', 'White')->count());
        $this->assertSame($before, $variant->fresh()->getAttributes());
        $this->assertSame(0, app(CatalogBatch::class)->import()['variants_created']);
        $this->assertSame($before, $variant->fresh()->getAttributes());
    }

    public function test_cart_order_and_provider_checkout_use_the_same_item_and_amount_without_rewriting_size_snapshots(): void
    {
        $variant = $this->variant($this->product());
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->post(route('cart.add'), ['variant_id' => $variant->id, 'quantity' => 3])->assertRedirect(route('cart'));
        $this->get(route('cart'))->assertOk()->assertSee('10 / White')->assertDontSee('#10 / White');
        $this->assertSame([$variant->id => 3], session('cart'));
        $order = app(OrderFlow::class)->reserve([$variant->id => 3], ['recipient' => 'Size Test', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0], $customer->id);
        $snapshot = $order->items->first()->getAttributes();
        $before = $variant->fresh()->getAttributes();
        $this->get(route('orders.show', $order))->assertOk()->assertSee('10 / White')->assertDontSee('#10 / White');
        $this->get(route('orders'))->assertOk()->assertSee('10 / White')->assertDontSee('#10 / White');

        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_numeric_size', 'shop.paymongo.webhook_secret' => 'size-fixture-secret']);
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => ['id' => 'cs_numeric_size',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_numeric_size']]])]);
        app(PayMongo::class)->create($order);
        Http::assertSent(fn ($request) => $request['data']['attributes']['line_items'] === [
            ['name' => 'YALEX KIDS / 10 / White', 'amount' => 10000, 'quantity' => 3, 'currency' => 'PHP'],
        ]);
        $this->assertSame($snapshot, $order->items()->first()->getAttributes());
        $this->assertSame('#10', $snapshot['size']);
        $this->assertSame($before, $variant->fresh()->getAttributes());
        $this->assertSame(30000, $order->fresh()->total);
    }

    public function test_assistance_recognizes_plain_and_legacy_numeric_names_without_mistaking_quantities_for_sizes(): void
    {
        $product = $this->product();
        $this->variant($product);
        $knowledge = app(StoreKnowledge::class);
        foreach (['10', '#10'] as $size) {
            $this->assertStringContainsString('10 / White: 20 pieces currently available',
                $knowledge->variantAnswer('Is YALEX KIDS size '.$size.' White available?', collect([$product])));
        }
        $context = $knowledge->catalogContext('Tell me about YALEX KIDS size 10 White', collect([$product]), collect([$product]))->first();
        $this->assertSame('10', $context['variants'][0]['size']);
        $this->assertSame('10', $context['size_chart'][0]['size']);
        $this->assertStringContainsString('please specify one size', $knowledge->variantAnswer('YALEX KIDS White 10 pieces', collect([$product])));
        Http::assertNothingSent();
    }
}
