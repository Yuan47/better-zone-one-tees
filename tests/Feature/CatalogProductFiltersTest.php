<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogProductFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function product(string $name, array $data = []): Product
    {
        return Product::create($data + ['name' => $name, 'slug' => 'filters-'.Str::uuid(),
            'description' => 'Isolated product filter fixture.', 'category' => '', 'active' => true]);
    }

    private function variant(Product $product, int $stock, int $reserved = 0, int $threshold = 10, array $data = []): void
    {
        $product->variants()->create($data + ['sku' => 'FILTER-'.Str::uuid(), 'size' => 'M', 'color' => 'White',
            'stock' => $stock, 'reserved' => $reserved, 'reorder_level' => $threshold,
            'retail_price' => 13200, 'wholesale_price' => 12200, 'reseller_price' => 11200]);
    }

    public function test_all_products_includes_published_and_hidden_but_keeps_archives_separate(): void
    {
        $published = $this->product('Published shirt');
        $hidden = $this->product('Hidden shirt', ['active' => false]);
        $archived = $this->product('Archived shirt', ['active' => false, 'archived_at' => now()]);

        foreach (['', '?filter=all', '?hidden=1'] as $query) {
            $this->get('/admin/products'.$query)->assertOk()->assertViewHas('catalogFilter', 'all')
                ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$hidden->id, $published->id])
                ->assertSee('All products')->assertSee('Low inventory')->assertSee('Hidden products')
                ->assertDontSee($archived->name);
        }
        $this->get('/admin/products?archived=1')->assertOk()
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$archived->id])
            ->assertDontSee('name="filter"', false)->assertDontSee($published->name)->assertDontSee($hidden->name);
        Http::assertNothingSent();
    }

    public function test_hidden_filter_is_exclusive_and_search_combines_with_each_scope(): void
    {
        $hidden = $this->product('Alpha hidden shirt', ['active' => false]);
        $this->product('Beta hidden shirt', ['active' => false]);
        $this->product('Alpha published shirt');
        $this->product('Alpha archived shirt', ['active' => false, 'archived_at' => now()]);
        $this->get('/admin/products?filter=hidden&q=Alpha')->assertOk()->assertViewHas('catalogFilter', 'hidden')
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$hidden->id]);
        $this->get('/admin/products?filter=all&q=Alpha')->assertOk()
            ->assertViewHas('products', fn ($products) => $products->count() === 2);
        $this->get('/admin/products?archived=1&q=missing')->assertOk()->assertSee('No archived products.');
    }

    public function test_low_inventory_is_based_on_each_active_variant_after_reservations_and_includes_hidden_products(): void
    {
        $healthy = $this->product('Healthy shirt');
        $this->variant($healthy, 11);
        $threshold = $this->product('At threshold shirt');
        $this->variant($threshold, 10);
        $reserved = $this->product('Reserved shirt');
        $this->variant($reserved, 30, 25);
        $hidden = $this->product('Hidden low shirt', ['active' => false]);
        $this->variant($hidden, 1);
        $mixed = $this->product('Mixed quantities shirt');
        $this->variant($mixed, 100);
        $this->variant($mixed, 0, 0, 0, ['size' => 'S']);
        $archivedVariant = $this->product('Archived size only shirt');
        $this->variant($archivedVariant, 0, 0, 10, ['archived_at' => now()]);
        $archivedProduct = $this->product('Archived product shirt', ['archived_at' => now()]);
        $this->variant($archivedProduct, 0);
        $this->product('No variants shirt');
        $expected = [$threshold->id, $hidden->id, $mixed->id, $reserved->id];
        $this->get('/admin/products?filter=low')->assertOk()->assertViewHas('catalogFilter', 'low')
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === $expected)
            ->assertSee('Available stock excludes reserved pieces')->assertSee('Hidden products are included and labeled.');
        $this->get('/admin/products?filter=low&q=Reserved')->assertOk()
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$reserved->id]);
        $this->assertSame(30, $reserved->variants()->sole()->stock);
        $this->assertSame(25, $reserved->variants()->sole()->reserved);
        Http::assertNothingSent();
    }

    public function test_filter_rejects_invalid_or_structured_values_and_customer_cannot_read_catalog_management(): void
    {
        foreach (['unexpected', ['all']] as $filter) {
            $this->getJson('/admin/products?'.http_build_query(['filter' => $filter]))
                ->assertUnprocessable()->assertJsonValidationErrors('filter');
        }
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get('/admin/products?filter=low')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get('/admin/products?filter=hidden')->assertForbidden();
    }
}
