<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryStatusTest extends TestCase
{
    use RefreshDatabase;

    public static function stockLevels(): array
    {
        return [
            'empty' => [0, 0, 10, 'critical'],
            'half threshold' => [5, 0, 10, 'critical'],
            'above half' => [6, 0, 10, 'alert'],
            'at threshold' => [10, 0, 10, 'alert'],
            'above threshold' => [11, 0, 10, 'healthy'],
            'held stock is unavailable' => [12, 8, 10, 'critical'],
            'held stock reaches threshold' => [12, 2, 10, 'alert'],
            'all stock held' => [12, 12, 10, 'critical'],
            'odd threshold lower half' => [2, 0, 5, 'critical'],
            'odd threshold upper half' => [3, 0, 5, 'alert'],
            'one piece threshold empty' => [0, 0, 1, 'critical'],
            'one piece threshold stocked' => [1, 0, 1, 'alert'],
            'zero threshold empty' => [0, 0, 0, 'critical'],
            'zero threshold stocked' => [1, 0, 0, 'healthy'],
        ];
    }

    #[DataProvider('stockLevels')]
    public function test_inventory_severity_uses_available_stock_and_the_saved_reorder_threshold(int $stock, int $reserved, int $threshold, string $expected): void
    {
        $variant = new Variant(['stock' => $stock, 'reserved' => $reserved, 'reorder_level' => $threshold]);
        $before = $variant->getAttributes();

        $this->assertSame($expected, $variant->inventoryStatus());
        $this->assertSame($before, $variant->getAttributes());
    }

    public static function workspaceRoles(): array
    {
        return [['admin'], ['staff']];
    }

    #[DataProvider('workspaceRoles')]
    public function test_workspace_shows_clear_severity_labels_and_quantities_without_changing_inventory(string $role): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $critical = $this->variant('Reserved critical Tee', 12, 8);
        $alert = $this->variant('Alert Tee', 8);
        $healthy = $this->variant('Healthy Tee', 11);
        $before = Variant::orderBy('id')->get()->map->getAttributes()->all();

        $this->get('/admin')->assertOk()
            ->assertSeeInOrder(['Reserved critical Tee', 'Critical low', '4 left', 'Alert Tee', 'Alert', '8 left'])
            ->assertViewHas('lowStock', fn ($rows) => $rows->pluck('id')->all() === [$critical->id, $alert->id]);
        $this->get('/admin/inventory-attention')->assertOk()
            ->assertSee('Inventory status')->assertSee('Out-of-stock variants appear first')
            ->assertSeeInOrder(['Reserved critical Tee', '4 pcs', 'Critical low', 'Alert Tee', '8 pcs', 'Alert'])
            ->assertViewHas('shortages', fn ($rows) => $rows->total() === 2 && ! $rows->pluck('id')->contains($healthy->id));
        if ($role === 'admin') {
            $this->get(route('admin.products.edit', $critical->product))->assertOk()
                ->assertSee('inventory-status-critical', false)->assertSee('Critical low')->assertSee('4 left');
            $this->get(route('admin.products.edit', $alert->product))->assertOk()
                ->assertSee('inventory-status-alert', false)->assertSee('Alert')->assertSee('8 left');
        }

        $this->assertSame($before, Variant::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    private function variant(string $name, int $stock, int $reserved = 0): Variant
    {
        $product = Product::create(['name' => $name, 'slug' => Str::slug($name), 'description' => 'Inventory status fixture', 'category' => '']);

        return $product->variants()->create(['sku' => Str::slug($name), 'size' => 'M', 'color' => 'White', 'color_group' => 'WHITE',
            'stock' => $stock, 'reserved' => $reserved, 'reorder_level' => 10,
            'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
    }
}
