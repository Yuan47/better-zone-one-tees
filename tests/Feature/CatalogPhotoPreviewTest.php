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
}
