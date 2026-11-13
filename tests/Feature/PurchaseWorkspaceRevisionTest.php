<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Services\Forecast;
use App\Services\SalesReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PurchaseWorkspaceRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-12 12:00:00', 'Asia/Manila'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function order(User $customer, string $number, array $attributes = []): Order
    {
        return Order::create($attributes + [
            'number' => $number, 'user_id' => $customer->id, 'tier' => 'retail', 'quantity' => 1,
            'merchandise_base' => 12345, 'subtotal' => 12345, 'shipping' => 0, 'total' => 12345,
            'payment_status' => 'paid', 'amount_paid' => 12345, 'paid_at' => '2026-11-10 10:00:00',
            'status' => 'packing', 'recipient' => 'Workspace buyer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'created_at' => '2026-11-10 09:00:00',
        ]);
    }

    private function item(Order $order, string $name, string $color = 'White'): void
    {
        $product = Product::create(['name' => $name, 'slug' => 'purchase-'.uniqid(), 'category' => '', 'description' => 'Shirt']);
        $variant = $product->variants()->create(['sku' => 'PURCHASE-'.uniqid(), 'size' => 'M', 'color' => $color,
            'stock' => 10, 'retail_price' => 12345, 'wholesale_price' => 12000, 'reseller_price' => 11000]);
        $order->items()->create(['variant_id' => $variant->id, 'name' => $name, 'size' => 'M', 'color' => $color,
            'quantity' => 1, 'unit_price' => 12345, 'total' => 12345]);
    }

    public function test_purchase_search_and_tab_counts_stay_scoped_to_the_signed_in_customer(): void
    {
        $customer = User::factory()->create();
        $owned = $this->order($customer, 'OWNED-SHIRT');
        $this->item($owned, 'Dannon V-Neck', 'Red');
        $other = $this->order(User::factory()->create(), 'OTHER-PRIVATE');
        $this->item($other, 'Private clothing', 'Teal');

        $this->actingAs($customer)->get(route('orders', ['q' => 'Red']))->assertOk()
            ->assertSee('My Purchases')->assertSee('OWNED-SHIRT')->assertDontSee('OTHER-PRIVATE')
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 1)
            ->assertViewHas('tabs', fn ($tabs) => collect($tabs)->firstWhere('status', 'all')['count'] === 1);
        $this->get(route('orders', ['q' => 'OTHER-PRIVATE']))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 0)->assertDontSee('Private clothing');
        Http::assertNothingSent();
    }

    public function test_purchase_tabs_require_customer_receipt_before_counting_completed_delivery(): void
    {
        $customer = User::factory()->create();
        $packing = $this->order($customer, 'PACKING');
        $ready = $this->order($customer, 'READY', ['status' => 'ready']);
        $courier = $this->order($customer, 'COURIER', ['status' => 'out_for_delivery', 'delivery_method' => 'lalamove']);
        $providerComplete = $this->order($customer, 'PROVIDER-COMPLETED', ['status' => 'completed', 'delivery_method' => 'lalamove', 'provider_completed_at' => now()]);
        $delivered = $this->order($customer, 'RECEIVED', ['status' => 'completed', 'customer_confirmed_at' => now()]);
        $cancelled = $this->order($customer, 'CANCELLED', ['status' => 'cancelled']);
        $pending = $this->order($customer, 'PENDING', ['payment_status' => 'pending', 'amount_paid' => 0, 'paid_at' => null, 'status' => 'awaiting_payment']);
        $oldBalance = $this->order($customer, 'OLDER-BALANCE', ['payment_status' => 'partially_paid', 'amount_paid' => 3000, 'paid_at' => null, 'status' => 'awaiting_payment']);
        $this->actingAs($customer);

        foreach (['packing' => [$packing->id], 'ready' => [$ready->id], 'to_ship' => [$courier->id, $providerComplete->id],
            'completed' => [$delivered->id], 'cancelled' => [$cancelled->id], 'to_pay' => [$pending->id, $oldBalance->id]] as $status => $ids) {
            $response = $this->get(route('orders', ['status' => $status]))->assertOk();
            $this->assertEqualsCanonicalizing($ids, $response->viewData('orders')->pluck('id')->all());
        }
        $this->assertSame(3000, $oldBalance->fresh()->amount_paid);
        Http::assertNothingSent();
    }

    public function test_return_filter_and_pagination_preserve_search_without_exposing_another_customer(): void
    {
        $customer = User::factory()->create();
        for ($index = 1; $index <= 16; $index++) {
            $order = $this->order($customer, 'HISTORY-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
            Refund::create(['order_id' => $order->id, 'reason' => 'Returned clothing', 'requested_amount' => 1000, 'status' => 'requested']);
        }
        $other = $this->order(User::factory()->create(), 'HISTORY-PRIVATE');
        Refund::create(['order_id' => $other->id, 'reason' => 'Private reason', 'requested_amount' => 1000, 'status' => 'requested']);
        $this->actingAs($customer);
        $response = $this->get(route('orders', ['status' => 'return_refund', 'q' => 'HISTORY']))->assertOk();
        $orders = $response->viewData('orders');
        $this->assertSame(16, $orders->total());
        $this->assertSame(15, $orders->count());
        parse_str(parse_url($orders->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('return_refund', $query['status']);
        $this->assertSame('HISTORY', $query['q']);
        $this->get($orders->nextPageUrl())->assertOk()->assertSee('HISTORY-01')->assertDontSee('HISTORY-PRIVATE');
        Http::assertNothingSent();
    }

    public function test_invalid_purchase_filters_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson(route('orders', ['status' => 'another-customer']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson(route('orders', ['q' => str_repeat('x', 101)]))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_long_daily_sales_chart_keeps_exact_centavos_with_a_bounded_readable_date_axis(): void
    {
        $customer = User::factory()->create();
        $this->order($customer, 'FIRST-DAY', ['paid_at' => '2026-07-16 00:00:00', 'subtotal' => 12345]);
        $this->order($customer, 'LAST-DAY', ['paid_at' => '2026-11-12 10:00:00', 'subtotal' => 67890]);
        $chart = app(SalesReport::class)->chart('daily', '2026-07-16', '2026-11-12');

        $this->assertCount(120, $chart['rows']);
        $this->assertSame(80235, $chart['total']);
        $this->assertSame(12345, $chart['rows'][0]['amount']);
        $this->assertSame(67890, $chart['rows'][119]['amount']);
        $this->assertLessThanOrEqual(1200, $chart['plot']['width']);
        $this->assertLessThanOrEqual(12, count($chart['plot']['label_indices']));
        $this->assertContains(0, $chart['plot']['label_indices']);
        $this->assertContains(119, $chart['plot']['label_indices']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.dashboard', ['period' => 'daily', 'start_date' => '2026-07-16', 'end_date' => '2026-11-12']))
            ->assertOk()->assertSee('₱802.35')->assertSee('₱123.45')->assertSee('₱678.90')->assertSee('Needs attention');
        Http::assertNothingSent();
    }

    public function test_dashboard_tasks_use_current_paid_fulfillment_and_exclude_archived_inventory(): void
    {
        $customer = User::factory()->create();
        $this->order($customer, 'PACK-NOW');
        $this->order($customer, 'READY-NOW', ['status' => 'ready']);
        $this->order($customer, 'READY-UNPAID', ['status' => 'ready', 'payment_status' => 'pending', 'amount_paid' => 0, 'paid_at' => null]);
        $archived = Product::create(['name' => 'Archived shirt', 'slug' => 'archived-task', 'category' => '', 'description' => 'Shirt', 'active' => true, 'archived_at' => now()]);
        $archived->variants()->create(['sku' => 'ARCHIVED-TASK', 'size' => 'M', 'color' => 'White', 'stock' => 0, 'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
        $response = $this->actingAs(User::factory()->create(['role' => 'staff']))->get(route('admin.dashboard'))->assertOk();
        $cards = collect($response->viewData('taskCards'));
        $this->assertSame(1, $cards->firstWhere('label', 'To pack')['count']);
        $this->assertSame(1, $cards->firstWhere('label', 'Ready for delivery')['count']);
        $this->assertSame(0, $cards->firstWhere('label', 'Low-stock variants')['count']);
        $response->assertSee('Staff dashboard')->assertDontSee('Archived shirt');
        Http::assertNothingSent();
    }

    public function test_archived_variant_sales_remain_in_history_while_its_stock_is_excluded_from_reorder_availability(): void
    {
        $customer = User::factory()->create();
        $product = Product::create(['name' => 'History shirt', 'slug' => 'history-shirt', 'category' => '',
            'description' => 'Shirt', 'active' => true, 'created_at' => '2026-08-01 09:00:00']);
        $details = ['size' => 'M', 'color' => 'White', 'retail_price' => 12345, 'wholesale_price' => 12000, 'reseller_price' => 11000];
        $old = $product->variants()->create($details + ['sku' => 'HISTORY-ARCHIVED', 'stock' => 50, 'archived_at' => now()]);
        $product->variants()->create(array_merge($details, ['sku' => 'HISTORY-ACTIVE', 'color' => 'Red', 'stock' => 7]));
        foreach (['2026-08-15' => 5, '2026-09-15' => 10, '2026-10-15' => 15] as $date => $quantity) {
            $order = $this->order($customer, 'HISTORY-'.$date, ['paid_at' => $date.' 10:00:00', 'quantity' => $quantity]);
            $order->items()->create(['variant_id' => $old->id, 'name' => $product->name, 'size' => 'M', 'color' => 'White',
                'quantity' => $quantity, 'unit_price' => 12345, 'total' => $quantity * 12345]);
        }

        $row = collect(app(Forecast::class)->rows())->firstWhere('name', 'History shirt');
        $this->assertSame([5, 10, 15], $row['values']);
        $this->assertSame(30, $row['sold']);
        $this->assertSame(7, $row['available']);
        $this->assertSame(20, $row['forecast']);
        $this->assertSame(13, $row['reorder']);
        $this->assertSame(50, $old->fresh()->stock);
        Http::assertNothingSent();
    }
}
