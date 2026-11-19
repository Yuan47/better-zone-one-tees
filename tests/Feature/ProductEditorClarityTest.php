<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductEditorClarityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('uploads');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'name' => 'Editor Tee', 'slug' => 'editor-tee-'.uniqid(), 'category' => '',
            'description' => 'Saved product description.', 'active' => true,
            'size_chart' => [['size' => 'M', 'width' => 19, 'length' => 27]],
        ]);
    }

    private function variant(Product $product, array $attributes = []): Variant
    {
        $attributes += ['size' => 'M', 'color' => 'White', 'color_group' => 'WHITE', 'stock' => 20,
            'reserved' => 0, 'reorder_level' => 5, 'retail_price' => 14000,
            'wholesale_price' => 13000, 'reseller_price' => 12000];

        return $product->variants()->create($attributes + ['sku' => Sku::forNewVariant($product, $attributes['size'], $attributes['color'])]);
    }

    private function productInput(Product $product, array $attributes = []): array
    {
        return $attributes + ['catalog_form' => 'product', 'name' => $product->name,
            'description' => $product->description, 'active' => $product->active ? '1' : '0',
            'size_chart' => $product->size_chart];
    }

    private function upload(string $name = 'current.png'): string
    {
        return 'uploads/'.Storage::disk('uploads')->putFile('products', UploadedFile::fake()->image($name));
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($document);
    }

    private function form(string $name): string
    {
        return '//form[input[@name="catalog_form" and @value="'.$name.'"]]';
    }

    private function value(\DOMXPath $xpath, string $form, string $name): string
    {
        return $xpath->evaluate('string('.$this->form($form).'//input[@name="'.$name.'"]/@value)');
    }

    public function test_product_photo_removal_is_saved_and_the_unshared_file_is_deleted(): void
    {
        $path = $this->upload();
        $product = $this->product(['image_path' => $path]);
        $variant = $this->variant($product);
        $xpath = $this->xpath($this->get(route('admin.products.edit', $product))->assertOk()->getContent());
        $this->assertSame(1, $xpath->query($this->form('product').'//input[@name="remove_photo"]')->length);
        $this->assertSame(0, $xpath->query($this->form('variant').'//input[@name="remove_photo"]')->length);

        $this->patch(route('admin.products.save', $product), $this->productInput($product, ['remove_photo' => '1']))
            ->assertRedirect(route('admin.products.edit', $product))->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()->image_path);
        Storage::disk('uploads')->assertMissing(substr($path, 8));
        $this->assertSame(20, $variant->fresh()->stock);
        Http::assertNothingSent();
    }

    public function test_replacement_photo_wins_when_removal_and_a_new_upload_are_submitted_together(): void
    {
        $path = $this->upload();
        $product = $this->product(['image_path' => $path]);

        $this->patch(route('admin.products.save', $product), $this->productInput($product, [
            'remove_photo' => '1', 'photo' => UploadedFile::fake()->image('replacement.png'),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $replacement = $product->fresh()->image_path;
        $this->assertNotNull($replacement);
        $this->assertNotSame($path, $replacement);
        Storage::disk('uploads')->assertExists(substr($replacement, 8));
        Storage::disk('uploads')->assertMissing(substr($path, 8));
        Http::assertNothingSent();
    }

    public function test_removing_product_photo_keeps_a_file_still_used_by_a_variant(): void
    {
        $path = $this->upload();
        $product = $this->product(['image_path' => $path]);
        $variant = $this->variant($product, ['image_path' => $path]);

        $this->patch(route('admin.products.save', $product), $this->productInput($product, ['remove_photo' => '1']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()->image_path);
        $this->assertSame($path, $variant->fresh()->image_path);
        Storage::disk('uploads')->assertExists(substr($path, 8));
        Http::assertNothingSent();
    }

    public function test_invalid_variant_values_reopen_only_the_variant_form_without_changing_product_details(): void
    {
        $product = $this->product(['image_path' => $this->upload()]);
        $variant = $this->variant($product, ['image_path' => $this->upload('variant.png')]);
        $url = route('admin.products.edit', [$product, 'variant' => $variant->id]);

        $this->from($url)->patch(route('admin.variants.save', [$product, $variant]), [
            'catalog_form' => 'variant', 'name' => 'Must not replace saved product', 'active' => '0',
            'size' => ['malformed'], 'color' => 'Correctable Teal', 'stock' => ['malformed'],
            'expected_stock' => ['malformed'], 'reorder_level' => 6,
            'retail' => 150, 'wholesale' => 140, 'reseller' => 130, 'remove_photo' => '1',
        ])->assertRedirect($url)->assertSessionHasErrors(['size', 'stock', 'expected_stock']);

        $xpath = $this->xpath($this->get($url)->assertOk()->getContent());
        $this->assertSame('Editor Tee', $this->value($xpath, 'product', 'name'));
        $this->assertSame('Correctable Teal', $this->value($xpath, 'variant', 'color'));
        $this->assertSame('20', $this->value($xpath, 'variant', 'stock'));
        $this->assertSame('20', $this->value($xpath, 'variant', 'expected_stock'));
        $this->assertSame(0, $xpath->query($this->form('product').'//input[@name="remove_photo"][@checked]')->length);
        $this->assertSame(1, $xpath->query($this->form('variant').'//input[@name="remove_photo"][@checked]')->length);
        $this->assertSame(1, $xpath->query($this->form('product').'//select[@name="active"]/option[@value="1"][@selected]')->length);
        $this->assertSame(['Editor Tee', 'White', 20], [$product->fresh()->name, $variant->fresh()->color, $variant->fresh()->stock]);
        Http::assertNothingSent();
    }

    public function test_invalid_product_input_does_not_fill_or_remove_the_variant_photo(): void
    {
        $product = $this->product(['image_path' => $this->upload()]);
        $variant = $this->variant($product, ['image_path' => $this->upload('variant.png')]);
        $url = route('admin.products.edit', [$product, 'variant' => $variant->id]);

        $this->from($url)->patch(route('admin.products.save', $product), [
            'catalog_form' => 'product', 'name' => ['malformed'], 'description' => 'Correctable description.',
            'active' => '1', 'remove_photo' => '1', 'color' => 'Must not populate the variant',
            'size_chart' => [['size' => ['malformed'], 'width' => ['malformed'], 'length' => 27]],
        ])->assertRedirect($url)->assertSessionHasErrors(['name', 'size_chart.0.size', 'size_chart.0.width']);

        $xpath = $this->xpath($this->get($url)->assertOk()->getContent());
        $this->assertSame('Editor Tee', $this->value($xpath, 'product', 'name'));
        $this->assertSame('Correctable description.', $xpath->evaluate('string('.$this->form('product').'//textarea[@name="description"])'));
        $this->assertSame('White', $this->value($xpath, 'variant', 'color'));
        $this->assertSame(1, $xpath->query($this->form('product').'//input[@name="remove_photo"][@checked]')->length);
        $this->assertSame(0, $xpath->query($this->form('variant').'//input[@name="remove_photo"][@checked]')->length);
        Storage::disk('uploads')->assertExists(substr($product->image_path, 8));
        Storage::disk('uploads')->assertExists(substr($variant->image_path, 8));
        Http::assertNothingSent();
    }

    public function test_bulk_color_shortcut_starts_without_a_source_and_only_exposes_active_size_prices(): void
    {
        $product = $this->product();
        $this->variant($product, ['size' => 'XL', 'color' => 'White', 'retail_price' => 16000]);
        $this->variant($product, ['size' => 'S', 'color' => 'White', 'retail_price' => 14000]);
        $this->variant($product, ['size' => 'M', 'color' => 'White', 'archived_at' => now(), 'retail_price' => 15000]);
        $this->variant($product, ['size' => 'L', 'color' => 'Archived Color', 'archived_at' => now()]);

        $xpath = $this->xpath($this->get(route('admin.products.edit', $product))->assertOk()->getContent());
        $source = $this->form('color').'//select[@name="template_color"]';
        $this->assertSame('', $xpath->evaluate('string(('.$source.'/option)[1]/@value)'));
        $this->assertSame('Choose an existing color', $xpath->evaluate('string(('.$source.'/option)[1])'));
        $this->assertSame(0, $xpath->query($source.'/option[@selected]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-color-price-preview][@hidden]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-color-price-rows]/tr')->length);
        $templates = json_decode($xpath->evaluate('string(//script[@id="catalog-color-templates"])'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['White'], array_keys($templates));
        $this->assertSame(['S', 'XL'], array_column($templates['White'], 'size'));
        $this->assertSame([14000, 16000], array_column($templates['White'], 'retail'));
        Http::assertNothingSent();
    }

    public function test_color_form_recovery_does_not_copy_new_color_into_the_individual_variant(): void
    {
        $product = $this->product();
        $this->variant($product);
        $url = route('admin.products.edit', $product);
        $this->from($url)->post(route('admin.colors.store', $product), [
            'catalog_form' => 'color', 'color' => 'Correctable Aqua', 'template_color' => ['malformed'],
        ])->assertRedirect($url)->assertSessionHasErrors('template_color');

        $xpath = $this->xpath($this->get($url)->assertOk()->getContent());
        $this->assertSame('Correctable Aqua', $this->value($xpath, 'color', 'color'));
        $this->assertSame('', $this->value($xpath, 'variant', 'color'));
        $this->assertSame(0, $xpath->query($this->form('color').'//select[@name="template_color"]/option[@selected]')->length);
        $this->assertSame(1, $xpath->query('//details[contains(@class,"catalog-color-shortcut")][@open]')->length);
        $this->assertSame(1, $product->variants()->count());
        Http::assertNothingSent();
    }

    public function test_color_groups_sort_alphabetically_and_adult_sizes_keep_logical_order_and_edit_links(): void
    {
        $product = $this->product();
        foreach (['5XL', '2X', 'M', 'XS', 'XL', 'S', '4XL', 'L', '3XL'] as $size) {
            $this->variant($product, ['size' => $size, 'color' => 'White']);
        }
        $red = $this->variant($product, ['size' => 'M', 'color' => 'red']);
        $this->variant($product, ['size' => 'M', 'color' => 'Blue']);

        $xpath = $this->xpath($this->get(route('admin.products.edit', $product))->assertOk()->getContent());
        $colors = array_map(fn ($node) => $node->getAttribute('data-variant-color'), iterator_to_array($xpath->query('//tbody[@data-variant-color]')));
        $sizes = array_map(fn ($node) => $node->textContent, iterator_to_array($xpath->query('//tbody[@data-variant-color="White"]/tr/td[2]/b')));
        $this->assertSame(['Blue', 'red', 'White'], $colors);
        $this->assertSame(['XS', 'S', 'M', 'L', 'XL', '2X', '3XL', '4XL', '5XL'], $sizes);
        $this->assertSame(1, $xpath->query('//a[@href="'.route('admin.products.edit', [$product, 'variant' => $red->id]).'#variant-editor"]')->length);
        $this->assertSame(11, $product->variants()->count());
        Http::assertNothingSent();
    }

    public function test_kids_sizes_sort_numerically_in_each_color_group(): void
    {
        $product = $this->product(['name' => 'Editor Kids Tee']);
        foreach (['#20', '#4', '#12', '#8', '#10', '#6', '#18', '#14', '#16'] as $size) {
            $this->variant($product, ['size' => $size]);
        }

        $xpath = $this->xpath($this->get(route('admin.products.edit', $product))->assertOk()->getContent());
        $sizes = array_map(fn ($node) => $node->textContent, iterator_to_array($xpath->query('//tbody[@data-variant-color="White"]/tr/td[2]/b')));
        $this->assertSame(['#4', '#6', '#8', '#10', '#12', '#14', '#16', '#18', '#20'], $sizes);
        Http::assertNothingSent();
    }
}
