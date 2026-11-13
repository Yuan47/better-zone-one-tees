<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Variant;
use App\Services\DannonProductImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DannonProductImagesTest extends TestCase
{
    use RefreshDatabase;

    private const COLORS = ['White', 'Red', 'Mandarin', 'Maroon', 'Batis', 'Teal', 'Light Blue', 'Fatigue', 'Gray', 'Pool Green', 'Gold', 'Yellow', 'Peach', 'Light Yellow'];

    private const STYLES = [
        'dannon-round-neck-v-neck' => ['round-neck', 11, 'adult'],
        'dannon-round-neck-v-neck-kids' => ['round-neck-kids', 15, 'kids'],
        'dannon-v-neck' => ['v-neck', 11, 'adult'],
        'dannon-v-neck-kids' => ['v-neck-kids', 15, 'kids'],
    ];

    private function originalPhoto(int $sourceId, string $color): string
    {
        return 'uploads/variants/democolor20261029_p'.$sourceId.'_'.strtolower(str_replace(' ', '_', $color)).'_'.substr(hash('sha256', $sourceId.'|'.$color), 0, 8).'.png';
    }

    private function product(string $slug, array $attributes = []): Product
    {
        return Product::create($attributes + ['slug' => $slug, 'name' => 'Owner product title', 'description' => 'Plain clothing', 'category' => '', 'active' => true]);
    }

    public function test_all_adult_and_kids_colors_resolve_to_one_correct_neckline_illustration(): void
    {
        foreach (self::STYLES as $slug => [$style, $sourceId, $age]) {
            foreach (self::COLORS as $color) {
                $relative = 'catalog-previews/dannon/'.$style.'/'.strtolower(str_replace(' ', '-', $color)).'.svg';
                $this->assertSame(asset($relative), DannonProductImages::url($slug, $this->originalPhoto($sourceId, $color)));
                $this->assertFileExists(public_path($relative));
                $svg = file_get_contents(public_path($relative));
                $xml = new \DOMDocument();
                $this->assertTrue($xml->loadXML($svg, LIBXML_NONET));
                $xpath = new \DOMXPath($xml);
                $shirts = $xpath->query('//*[@data-garment]');
                $this->assertSame(1, $shirts->length);
                $this->assertSame(str_starts_with($style, 'v-neck') ? 'v-neck' : 'round-neck', $shirts[0]->getAttribute('data-garment'));
                $this->assertSame($age, $shirts[0]->getAttribute('data-size-range'));
                $this->assertStringContainsString('/ '.$color, $xml->getElementsByTagName('title')[0]->textContent);
                $this->assertStringNotContainsString('ROUND NECK / V-NECK', $svg);
                $this->assertDoesNotMatchRegularExpression('/<script|foreignObject|\shref=|\son[a-z]+\s*=/i', $svg);
                $this->assertStringContainsString('ILLUSTRATION', $svg);
            }
        }
    }

    public function test_product_pages_publish_distinct_photos_without_changing_saved_catalog_values(): void
    {
        foreach (self::STYLES as $slug => [$style, $sourceId]) {
            $product = $this->product($slug, ['image_path' => $this->originalPhoto($sourceId, 'White')]);
            foreach (self::COLORS as $index => $color) {
                $product->variants()->create([
                    'sku' => 'PREVIEW-'.$product->id.'-'.$index, 'color' => $color, 'size' => 'M',
                    'image_path' => $this->originalPhoto($sourceId, $color), 'stock' => 23, 'reserved' => 2,
                    'retail_price' => 14200, 'wholesale_price' => 13200, 'reseller_price' => 12700,
                ]);
            }
            $beforeProduct = $product->fresh()->getAttributes();
            $beforeVariants = $product->variants()->orderBy('id')->get()->toArray();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->get(route('product', $product))->assertOk();
            $productQueries = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/from\s+["`]?products["`]?\s/i', $query['query']));
            DB::disableQueryLog();
            $this->assertLessThanOrEqual(2, count($productQueries), 'Photo selection must reuse its known product instead of querying once per variant.');
            preg_match('/<script type="application\/json" id="product-variants">(.*?)<\/script>/s', $response->getContent(), $matches);
            $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(14, $data);
            foreach ($data as $variant) {
                $this->assertSame(asset('catalog-photos/'.$style.'.png'), $variant['photo']);
                $this->assertTrue($variant['demo_photo']);
                $this->assertSame(\App\Services\CatalogPhotoPreview::namedColor($variant['color']), $variant['photo_color']);
                $this->assertSame([14200, 13200, 12700, 21], [$variant['retail'], $variant['wholesale'], $variant['reseller'], $variant['stock']]);
            }
            $this->assertSame($beforeProduct, $product->fresh()->getAttributes());
            $this->assertSame($beforeVariants, $product->variants()->orderBy('id')->get()->toArray());
        }
    }

    public function test_owner_photos_urls_blank_variants_and_other_products_keep_normal_precedence(): void
    {
        $product = $this->product('dannon-v-neck', ['image_path' => 'uploads/products/owner.jpg', 'image_url' => 'https://example.test/old.jpg']);
        $this->assertSame(asset('uploads/products/owner.jpg'), $product->photoUrl());
        $variant = new Variant(['image_path' => 'uploads/variants/owner.webp', 'product_id' => $product->id]);
        $variant->setRelation('product', $product);
        $this->assertSame(asset('uploads/variants/owner.webp'), $variant->photoUrl($product));
        $variant->image_path = null;
        $this->assertSame($product->photoUrl(), $variant->photoUrl($product));
        $product->image_path = null;
        $this->assertSame('https://example.test/old.jpg', $product->photoUrl());
        $this->assertSame('https://example.test/old.jpg', $variant->photoUrl($product));
        $product->image_url = null;
        $this->assertSame(asset('catalog-photos/v-neck.png'), $variant->photoUrl($product));
        $this->assertNull((new Product())->photoUrl());
        $legacy = $this->originalPhoto(11, 'Red');
        $other = $this->product('lucky-hanna-v-neck', ['image_path' => $legacy]);
        $this->assertSame(asset('catalog-photos/v-neck.png'), $other->photoUrl());
        $variant->image_path = $legacy;
        $variant->product_id = $other->id;
        $variant->setRelation('product', $other);
        $this->assertSame(asset('catalog-photos/v-neck.png'), $variant->photoUrl($other));
    }

    public function test_lookalike_or_unrecognized_paths_cannot_select_a_controlled_preview(): void
    {
        foreach ([null, '', 'uploads/products/owner.png', 'uploads/variants/democolor20261029_p11_white_fake.png',
            'uploads/variants/../variants/democolor20261029_p11_white_afa82c13.png',
            'https://example.test/uploads/variants/democolor20261029_p11_white_afa82c13.png',
            'uploads/variants/democolor20261029_p11_white_afa82c13.png/other'] as $path) {
            $this->assertNull(DannonProductImages::url('dannon-v-neck', $path));
        }
        $this->assertNull(DannonProductImages::url('another-shirt', $this->originalPhoto(11, 'White')));
        $this->assertNull(DannonProductImages::url(null, $this->originalPhoto(11, 'White')));
    }
}
