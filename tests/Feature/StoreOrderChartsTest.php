<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StoreOrderCharts;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoreOrderChartsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-13 12:00:00', 'Asia/Manila'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function product(string $name): Product
    {
        $product = Product::create(['name' => $name, 'slug' => uniqid('chart-'), 'category' => '', 'description' => 'Chart fixture']);
        $product->variants()->create(['sku' => uniqid('CHART-'), 'color' => 'White', 'size' => 'M', 'stock' => 500,
            'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);

        return $product;
    }

    private function sale(Product $product, string $date, int $pieces, string $channel = 'online', string $status = 'paid'): Order
    {
        $order = Order::create(['number' => uniqid('CHART-'), 'user_id' => null, 'sales_channel' => $channel, 'tier' => 'retail',
            'quantity' => $pieces, 'subtotal' => $pieces * 10000, 'merchandise_base' => $pieces * 10000, 'total' => $pieces * 10000,
            'amount_paid' => $status === 'paid' ? $pieces * 10000 : 0, 'payment_status' => $status,
            'paid_at' => $status === 'paid' ? $date : null, 'status' => 'packing', 'recipient' => 'Demo buyer', 'phone' => '09123456789', 'address' => 'Store pickup', 'created_at' => $date]);
        $order->items()->create(['variant_id' => $product->variants->first()->id, 'name' => $product->name, 'size' => 'M', 'color' => 'White',
            'quantity' => $pieces, 'unit_price' => 10000, 'total' => $pieces * 10000]);

        return $order;
    }

    public function test_top_five_updates_from_paid_purchases_with_stable_product_identity_and_four_month_boundaries(): void
    {
        $first = $this->product('Original name');
        $this->sale($first, '2026-08-01 00:00:00', 4);
        $this->sale($first, '2026-10-31 10:00:00', 6, 'walk_in');
        $first->update(['name' => 'Renamed product']);
        $this->sale($first, '2026-11-13 11:59:59', 9);
        $this->sale($first, '2026-07-31 23:59:59', 900);
        $this->sale($first, '2026-11-13 12:00:01', 800);
        $this->sale($first, '2026-11-10 10:00:00', 700, 'online', 'pending');
        foreach ([2, 3, 5, 7, 11] as $index => $pieces) $this->sale($this->product('Product '.$index), '2026-09-05 10:00:00', $pieces);

        $chart = app(StoreOrderCharts::class)->snapshot();
        $this->assertSame(['2026-08', '2026-09', '2026-10', '2026-11'], array_column($chart['months'], 'key'));
        $this->assertCount(5, $chart['series']);
        $this->assertSame(['product_id' => $first->id, 'name' => 'Renamed product', 'total' => 19, 'values' => [4, 0, 6, 9]], array_intersect_key($chart['series'][0], array_flip(['product_id', 'name', 'total', 'values'])));
        $this->assertSame([19, 11, 7, 5, 3], array_column($chart['series'], 'total'));
        $this->assertSame([1, 7], array_column($chart['channels'], 'count'));
        $this->assertSame([12.5, 87.5], array_column($chart['channels'], 'percentage'));
        $this->sale($first, '2026-11-13 12:00:00', 2, 'walk_in');
        $this->assertSame(21, app(StoreOrderCharts::class)->snapshot()['series'][0]['total']);
        Http::assertNothingSent();
    }

    public function test_staff_and_owner_see_simple_channel_and_top_item_graphs_and_empty_states(): void
    {
        foreach (['staff', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('admin.dashboard'))->assertOk()
                ->assertSee('Walk-in &amp; online orders', false)->assertSee('Top 5 most bought items')->assertSee('Last 4 months')
                ->assertDontSee('Fully paid vs outstanding invoices')->assertSee('No confirmed walk-in or online orders in the last 4 months.');
        }
        $this->sale($this->product('New popular shirt'), '2026-11-13 10:00:00', 3);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('New popular shirt')->assertSee('3.00 pieces');
        Http::assertNothingSent();
    }
}
