<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Variant;
use App\Services\CatalogBatch;
use App\Services\CatalogPhotoPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPhotoPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_current_catalog_style_has_a_photographic_preview_and_preserves_owner_uploads(): void
    {
        app(CatalogBatch::class)->import();
        foreach (Product::published()->with('variants')->get() as $product) {
            $this->assertTrue($product->usesDemoPhoto(), $product->slug);
            $this->assertStringContainsString('/catalog-photos/', $product->photoUrl());
            $relative = parse_url($product->photoUrl(), PHP_URL_PATH);
            $this->assertFileExists(public_path(ltrim($relative, '/')));
            $image = getimagesize(public_path(ltrim($relative, '/')));
            $this->assertSame('image/png', $image['mime']);
            $this->assertGreaterThanOrEqual(1000, $image[0]);
            $variant = $product->variants->first();
            $variant->image_path = 'uploads/variants/owner-photo.webp';
            $this->assertFalse($variant->usesDemoPhoto($product));
            $this->assertSame(asset('uploads/variants/owner-photo.webp'), $variant->photoUrl($product));
        }
    }

    public function test_selected_color_is_presented_separately_from_the_unchanged_photo_master(): void
    {
        $product = Product::create(['name' => 'Dannon V-Neck', 'slug' => 'dannon-v-neck', 'description' => 'Plain demo shirt', 'category' => '', 'active' => true]);
        $variant = $product->variants()->create(['sku' => 'PHOTO-RED-M', 'size' => 'M', 'color' => 'Red',
            'stock' => 15, 'reserved' => 0, 'retail_price' => 15000, 'wholesale_price' => 14000, 'reseller_price' => 13000]);
        $before = $variant->fresh()->getAttributes();
        $response = $this->get(route('product', $product))->assertOk()
            ->assertSee('Photorealistic demo preview')->assertDontSee('SAMPLE ILLUSTRATION');
        preg_match('/<script type="application\/json" id="product-variants">(.*?)<\/script>/s', $response->getContent(), $matches);
        $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('#CF3843', $data[0]['photo_color']);
        $this->assertTrue($data[0]['demo_photo']);
        $this->assertSame($before, $variant->fresh()->getAttributes());
        $this->assertSame('#ffffff', CatalogPhotoPreview::color('url(javascript:alert(1))'));
        $this->assertFalse((new Product(['slug' => 'unrecognized-owner-product']))->usesDemoPhoto());
    }

    public function test_storefront_uses_the_photographic_banner_and_cards(): void
    {
        app(CatalogBatch::class)->import();
        $this->get(route('store'))->assertOk()->assertSee('catalog-photos/storefront-hero.png', false)
            ->assertSee('data-catalog-photo', false)->assertSee('DEMO PREVIEW')->assertDontSee('hero-shirt one', false);
    }

    public function test_legacy_demo_styles_show_matching_photographs_while_active_or_hidden_without_changing_data(): void
    {
        $styles = [
            'everyday-cotton-tee' => 'round-neck',
            'the-classic-crew' => 'round-neck',
            'relaxed-pocket-tee' => 'pocket-tee-v1',
            'essential-polo' => 'polo',
            'active-dri-fit-tee' => 'athletic-tee-v1',
            'weekend-oversized-tee' => 'oversized-tee-v1',
            'everyday-kids-tee' => 'round-neck-kids',
            'classic-pique-polo' => 'polo',
        ];
        foreach ($styles as $slug => $style) {
            $product = Product::create(['name' => $slug, 'slug' => $slug, 'description' => 'Original demo shirt', 'category' => '', 'active' => false]);
            $variant = $product->variants()->create(['sku' => 'LEGACY-'.strtoupper($slug), 'size' => 'M', 'color' => 'Red',
                'stock' => 15, 'reserved' => 2, 'retail_price' => 15000, 'wholesale_price' => 14000, 'reseller_price' => 13000,
                'image_path' => 'uploads/variants/democolor20261029_p2_red_deadbeef.png']);
            $variantBefore = $variant->fresh()->getAttributes();
            foreach ([false, true] as $active) {
                $product->update(['active' => $active]);
                $productBefore = $product->fresh()->getAttributes();
                $expected = asset('catalog-photos/'.$style.'.png');
                $this->assertTrue($product->usesDemoPhoto(), $slug);
                $this->assertTrue($variant->usesDemoPhoto($product), $slug);
                $this->assertSame($expected, $product->photoUrl());
                $this->assertSame($expected, $variant->photoUrl($product));
                $this->assertSame($productBefore, $product->fresh()->getAttributes());
                $this->assertSame($variantBefore, $variant->fresh()->getAttributes());
            }
            $imagePath = public_path('catalog-photos/'.$style.'.png');
            $this->assertFileExists($imagePath);
            $image = getimagesize($imagePath);
            $this->assertSame('image/png', $image['mime']);
            $this->assertGreaterThanOrEqual(1000, $image[0]);
        }
    }

    public function test_legacy_demo_products_keep_owner_photos_and_external_images_with_blank_variant_inheritance(): void
    {
        $product = Product::create(['name' => 'Relaxed Pocket Tee', 'slug' => 'relaxed-pocket-tee', 'description' => '', 'category' => '', 'active' => false,
            'image_path' => 'uploads/products/owner-shirt.webp']);
        $variant = $product->variants()->create(['sku' => 'OWNER-POCKET-M', 'size' => 'M', 'color' => 'White',
            'stock' => 10, 'reserved' => 0, 'retail_price' => 15000, 'wholesale_price' => 14000, 'reseller_price' => 13000]);
        $this->assertFalse($product->usesDemoPhoto());
        $this->assertFalse($variant->usesDemoPhoto($product));
        $this->assertSame(asset('uploads/products/owner-shirt.webp'), $product->photoUrl());
        $this->assertSame($product->photoUrl(), $variant->photoUrl($product));

        $product->update(['image_path' => null, 'image_url' => 'https://example.test/owner-shirt.jpg']);
        $this->assertFalse($product->usesDemoPhoto());
        $this->assertFalse($variant->usesDemoPhoto($product));
        $this->assertSame('https://example.test/owner-shirt.jpg', $product->photoUrl());
        $this->assertSame($product->photoUrl(), $variant->photoUrl($product));

        $variant->image_path = 'uploads/variants/owner-color.webp';
        $this->assertFalse($variant->usesDemoPhoto($product));
        $this->assertSame(asset('uploads/variants/owner-color.webp'), $variant->photoUrl($product));
    }

    public function test_unknown_owner_products_do_not_receive_guessed_shirt_photos(): void
    {
        foreach (['Test1', 'w', 'Yuan', 'Canvas Tote Bag'] as $name) {
            $product = new Product(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'category' => '', 'active' => false]);
            $this->assertFalse($product->usesDemoPhoto());
            $this->assertNull($product->photoUrl());
        }
    }

    public function test_storefront_does_not_label_an_owner_variant_photo_as_a_demo(): void
    {
        $product = Product::create(['name' => 'Relaxed Pocket Tee', 'slug' => 'relaxed-pocket-tee', 'description' => '', 'category' => '', 'active' => true]);
        $product->variants()->create(['sku' => 'CARD-POCKET-M', 'size' => 'M', 'color' => 'White',
            'stock' => 10, 'reserved' => 0, 'retail_price' => 15000, 'wholesale_price' => 14000, 'reseller_price' => 13000,
            'image_path' => 'uploads/variants/owner-card.webp']);
        $this->get(route('store', ['q' => 'Relaxed Pocket Tee']))->assertOk()
            ->assertSee('uploads/variants/owner-card.webp', false)->assertDontSee('DEMO PREVIEW');
    }
}
