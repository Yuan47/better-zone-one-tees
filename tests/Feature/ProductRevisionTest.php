<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    private function admin(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    private function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'name' => 'Revision Tee', 'slug' => 'revision-tee-'.uniqid(),
            'category' => 'Legacy Category', 'description' => 'A plain shirt',
            'active' => true, 'size_chart' => [['size' => 'M', 'width' => 19.5, 'length' => 27]],
            'size_chart_note' => 'Owner confirmed sample chart',
        ]);
    }

    private function productInput(array $attributes = []): array
    {
        return $attributes + [
            'name' => 'Photo Shirt', 'description' => 'A shirt with its own uploaded photograph.', 'active' => 1,
            'size_chart' => [['size' => ' m ', 'width' => 19.5, 'length' => 27], ['size' => '2xl', 'width' => null, 'length' => null]],
            'size_chart_note' => 'Measurements in inches supplied by the store.',
        ];
    }

    private function variantInput(array $attributes = []): array
    {
        return $attributes + [
            'size' => 'M', 'color_group' => 'COLORED', 'color' => 'Red', 'stock' => 10, 'reorder_level' => 3,
            'retail' => 140, 'wholesale' => 130, 'reseller' => 125,
        ];
    }

    private function variant(Product $product, array $attributes = []): Variant
    {
        $attributes += ['size' => 'M', 'color' => 'Red', 'color_group' => 'COLORED', 'stock' => 10, 'reorder_level' => 3,
            'retail_price' => 14000, 'wholesale_price' => 13000, 'reseller_price' => 12500];

        return $product->variants()->create($attributes + ['sku' => Sku::forVariant($product, $attributes['size'], $attributes['color'])]);
    }

    public function test_admin_creates_product_with_photo_and_editable_size_chart_without_category(): void
    {
        $this->admin();
        $this->post(route('admin.products.store'), $this->productInput(['photo' => UploadedFile::fake()->image('../../shirt.jpg')]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $product = Product::where('name', 'Photo Shirt')->firstOrFail();
        $this->assertSame('', $product->category);
        $this->assertMatchesRegularExpression('#^uploads/products/[a-zA-Z0-9]+\.jpg$#', $product->image_path);
        Storage::disk('uploads')->assertExists(substr($product->image_path, 8));
        $this->assertEquals([
            ['size' => 'M', 'width' => 19.5, 'length' => 27],
            ['size' => '2XL', 'width' => null, 'length' => null],
        ], $product->size_chart);
        $this->get(route('product', $product))->assertOk()->assertSee($product->photoUrl(), false)
            ->assertSee('Width (in.)')->assertSee('Length (in.)')->assertSee('19.5')->assertSee('To be confirmed');
    }

    public function test_upload_validation_rejects_nonimages_svg_and_files_over_five_megabytes(): void
    {
        $this->admin();
        $files = [
            UploadedFile::fake()->createWithContent('pretend.jpg', '<?php echo "not an image";')->mimeType('application/x-php'),
            UploadedFile::fake()->createWithContent('script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')->mimeType('image/svg+xml'),
            UploadedFile::fake()->image('huge.png')->size(5121),
        ];
        foreach ($files as $file) {
            $this->post(route('admin.products.store'), $this->productInput(['photo' => $file]))
                ->assertSessionHasErrors('photo');
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_supplied_image_paths_are_never_persisted_and_text_photo_paths_are_rejected(): void
    {
        $this->admin();
        $this->post(route('admin.products.store'), $this->productInput(['photo' => '../../outside.php']))
            ->assertSessionHasErrors('photo');
        $this->post(route('admin.products.store'), $this->productInput([
            'image_path' => 'uploads/../../outside.php', 'image_url' => 'https://attacker.test/photo.svg', 'category' => 'Injected category',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->assertNull($product->image_path);
        $this->assertNull($product->image_url);
        $this->assertSame('', $product->category);
    }

    public function test_variant_sku_is_automatic_and_white_group_normalizes_color_and_size(): void
    {
        $this->admin();
        $product = $this->product();
        $this->post(route('admin.variants.store', $product), $this->variantInput([
            'sku' => 'INJECTED-SKU', 'size' => ' m ', 'color_group' => 'WHITE', 'color' => 'Mandarin',
            'photo' => UploadedFile::fake()->image('white.png'), 'image_path' => '../../bad.png',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $variant = $product->variants()->firstOrFail();
        $this->assertSame('M', $variant->size);
        $this->assertSame('White', $variant->color);
        $this->assertSame('WHITE', $variant->color_group);
        $this->assertSame(Sku::forVariant($product, 'M', 'White'), $variant->sku);
        $this->assertNotSame('INJECTED-SKU', $variant->sku);
        $this->assertSame([14000, 13000, 12500], [$variant->retail_price, $variant->wholesale_price, $variant->reseller_price]);
        $this->assertStringStartsWith('uploads/variants/', $variant->image_path);
        Storage::disk('uploads')->assertExists(substr($variant->image_path, 8));
    }

    public function test_duplicate_variants_invalid_tier_prices_and_colored_white_are_rejected(): void
    {
        $this->admin();
        $product = $this->product();
        $this->variant($product);
        $this->post(route('admin.variants.store', $product), $this->variantInput())->assertSessionHasErrors('color');
        $this->post(route('admin.variants.store', $product), $this->variantInput(['color' => 'Blue', 'wholesale' => 150]))
            ->assertSessionHasErrors('prices');
        $this->post(route('admin.variants.store', $product), $this->variantInput(['color' => 'Blue', 'reseller' => 135]))
            ->assertSessionHasErrors('prices');
        $this->post(route('admin.variants.store', $product), $this->variantInput(['color' => 'White']))
            ->assertSessionHasErrors('color');
        $this->assertSame(1, $product->variants()->count());
    }

    public function test_new_color_copies_each_colored_size_price_and_keeps_zero_stock(): void
    {
        $this->admin();
        $product = $this->product();
        $this->variant($product, ['size' => 'M', 'retail_price' => 15200, 'wholesale_price' => 14200, 'reseller_price' => 13700]);
        $this->variant($product, ['size' => 'XL', 'retail_price' => 18200, 'wholesale_price' => 17200, 'reseller_price' => 16700]);
        $this->variant($product, ['size' => 'XS', 'color' => 'White', 'color_group' => 'WHITE']);
        $this->post(route('admin.colors.store', $product), ['color' => 'Custom Aqua', 'photo' => UploadedFile::fake()->image('aqua.png')])
            ->assertRedirect()->assertSessionHasNoErrors();
        $new = $product->variants()->where('color', 'Custom Aqua')->orderBy('id')->get();
        $this->assertSame(['M', 'XL'], $new->pluck('size')->all());
        $this->assertSame([[15200, 14200, 13700], [18200, 17200, 16700]], $new->map(fn ($v) => [$v->retail_price, $v->wholesale_price, $v->reseller_price])->all());
        $this->assertSame(0, $new->sum('stock'));
        $this->assertSame(0, $new->sum('reserved'));
        $this->assertSame(['COLORED'], $new->pluck('color_group')->unique()->all());
        $this->assertCount(1, $new->pluck('image_path')->unique());
        foreach ($new as $variant) {
            $this->assertSame(Sku::forVariant($product, $variant->size, 'Custom Aqua'), $variant->sku);
        }
    }

    public function test_removing_one_size_photo_preserves_shared_color_photo_until_last_reference_is_removed(): void
    {
        $this->admin();
        $product = $this->product();
        $this->variant($product, ['size' => 'M']);
        $this->variant($product, ['size' => 'L']);
        $this->post(route('admin.colors.store', $product), ['color' => 'Aqua', 'photo' => UploadedFile::fake()->image('shared.png')])
            ->assertSessionHasNoErrors();
        $sizes = $product->variants()->where('color', 'Aqua')->orderBy('id')->get();
        $path = $sizes[0]->image_path;
        foreach ($sizes as $i => $variant) {
            $this->patch(route('admin.variants.save', [$product, $variant]), $this->variantInput([
                'size' => $variant->size, 'color' => 'Aqua', 'stock' => 0, 'remove_photo' => 1,
            ]))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertNull($variant->fresh()->image_path);
            if ($i === 0) {
                $this->assertSame($path, $sizes[1]->fresh()->image_path);
                Storage::disk('uploads')->assertExists(substr($path, 8));
            }
        }
        Storage::disk('uploads')->assertMissing(substr($path, 8));
    }

    public function test_staff_cannot_create_products_variants_or_colors_or_upload_images(): void
    {
        $this->admin('staff');
        $product = $this->product();
        $this->get(route('admin.products.create'))->assertForbidden();
        $this->post(route('admin.products.store'), $this->productInput(['photo' => UploadedFile::fake()->image('product.png')]))->assertForbidden();
        $this->post(route('admin.variants.store', $product), $this->variantInput(['photo' => UploadedFile::fake()->image('variant.png')]))->assertForbidden();
        $this->post(route('admin.colors.store', $product), ['color' => 'Unauthorized', 'photo' => UploadedFile::fake()->image('color.png')])->assertForbidden();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('variants', 0);
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_store_uses_dynamic_size_color_filters_and_hides_category_and_reserved_fields(): void
    {
        $product = $this->product();
        $this->variant($product, ['size' => '4XL', 'color' => 'Batis']);
        $other = $this->product(['name' => 'Other Shirt']);
        $this->variant($other, ['size' => 'S', 'color' => 'Gray']);
        $this->get('/')->assertOk()->assertSee('4XL')->assertSee('Batis')
            ->assertDontSee('name="category"', false)->assertDontSee('Legacy Category');
        $this->get('/?size=4XL&color=Batis')->assertOk()->assertSee('Revision Tee')->assertDontSee('Other Shirt');
        $this->get(route('product', $product))->assertOk()->assertSee('Size chart')->assertSee('19.5')
            ->assertSee('Owner confirmed sample chart')->assertDontSee('Legacy Category');
        $this->admin();
        $this->get(route('admin.products.create'))->assertOk()->assertDontSee('name="category"', false)
            ->assertDontSee('name="image_url"', false);
        $this->get(route('admin.products.edit', $product))->assertOk()->assertDontSee('<th>Reserved</th>', false)
            ->assertDontSee('name="reserved"', false)->assertDontSee('name="sku"', false)->assertSee('Automatic SKU');
    }

    public function test_archived_products_are_unavailable_and_existing_cart_entries_are_cleared(): void
    {
        $archived = $this->product(['active' => false, 'name' => 'Archived Sample']);
        $variant = $this->variant($archived);
        $active = $this->product(['name' => 'Available Tee']);
        $available = $this->variant($active);
        $this->get(route('product', $archived))->assertNotFound();
        $this->post(route('cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertNotFound();
        $this->withSession(['cart' => [$variant->id => 1, $available->id => 2]])->get(route('cart'))
            ->assertOk()->assertSee('Available Tee')->assertDontSee('Archived Sample')
            ->assertSessionHas('cart', [$available->id => 2]);
    }

    public function test_combined_filters_require_one_variant_to_match_size_color_and_availability(): void
    {
        $product = $this->product(['name' => 'Limited White Polo']);
        $this->variant($product, ['size' => 'M', 'color' => 'White', 'color_group' => 'WHITE', 'stock' => 0]);
        $this->variant($product, ['size' => '5XL', 'color' => 'Red', 'stock' => 10, 'reserved' => 10]);
        $this->variant($product, ['size' => 'L', 'color' => 'Teal', 'stock' => 10]);

        $this->get('/?size=5XL&color=White')->assertOk()->assertDontSee('Limited White Polo');
        $this->get('/?size=5XL&color=Red')->assertOk()->assertSee('Limited White Polo');
        $this->get('/?size=M&color=White&in_stock=1')->assertOk()->assertDontSee('Limited White Polo');
        $this->get('/?size=5XL&color=Red&in_stock=1')->assertOk()->assertDontSee('Limited White Polo');
        $this->get('/?size=L&color=Teal&in_stock=1')->assertOk()->assertSee('Limited White Polo');
    }

    public function test_size_chart_editor_reindexes_sparse_validation_input_before_adding_rows(): void
    {
        $this->admin();
        $product = $this->product();
        $this->withSession(['_old_input' => ['size_chart' => [
            0 => ['size' => 'S', 'width' => 18, 'length' => 26],
            2 => ['size' => 'L', 'width' => 20, 'length' => 28],
        ]]])->get(route('admin.products.edit', $product))->assertOk()
            ->assertSee('name="size_chart[0][size]"', false)
            ->assertSee('name="size_chart[1][size]"', false)
            ->assertDontSee('name="size_chart[2][size]"', false);
    }
}
