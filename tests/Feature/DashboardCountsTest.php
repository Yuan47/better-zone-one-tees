<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-16 12:00:00', 'Asia/Manila'));
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function invoice(array $attributes = []): Order
    {
        return Order::create($attributes + [
            'number' => 'COUNT-'.uniqid(), 'user_id' => null, 'sales_channel' => 'walk_in',
            'tier' => 'retail', 'quantity' => 2, 'merchandise_base' => 20000, 'discount' => 0,
            'subtotal' => 20000, 'shipping' => 0, 'total' => 20000, 'amount_paid' => 20000,
            'payment_status' => 'paid', 'paid_at' => '2026-10-15 10:00:00', 'status' => 'completed',
            'walk_in_received_at' => '2026-10-15 10:00:00', 'recipient' => 'Guest buyer',
            'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup',
            'created_at' => '2026-10-15 10:00:00',
        ]);
    }

    public function test_dashboard_keeps_paid_date_sales_and_channels_without_redundant_delivery_charts(): void
    {
        $pickup = $this->invoice(['created_at' => '2026-10-14 00:00:00']);
        $courier = $this->invoice(['created_at' => '2026-10-15 23:59:59', 'paid_at' => '2026-10-16 10:00:00',
            'delivery_method' => 'lalamove', 'status' => 'ready', 'walk_in_received_at' => null]);
        $this->invoice(['created_at' => '2026-10-13 23:59:59', 'subtotal' => 40000]);
        $partial = $this->invoice(['payment_status' => 'partially_paid', 'amount_paid' => 10000, 'paid_at' => null,
            'status' => 'awaiting_payment', 'subtotal' => 120000, 'total' => 120000]);
        $pending = $this->invoice(['payment_status' => 'pending', 'amount_paid' => 0, 'paid_at' => null, 'status' => 'awaiting_payment']);
        $this->invoice(['status' => 'cancelled', 'subtotal' => 50000]);
        $this->invoice(['payment_status' => 'partially_paid', 'status' => 'cancelled', 'amount_paid' => 5000, 'paid_at' => null]);
        $this->invoice(['payment_status' => 'pending', 'status' => 'awaiting_payment', 'amount_paid' => 0,
            'inventory_released' => true, 'paid_at' => null]);
        $this->invoice(['payment_status' => 'failed', 'amount_paid' => 0, 'paid_at' => null]);
        $filters = ['start_date' => '2026-10-14', 'end_date' => '2026-10-15'];

        $this->get(route('admin.dashboard', $filters))->assertOk()
            ->assertViewHas('ordersCount', 3)->assertViewHas('revenue', 110000)
            ->assertSee('Walk-in &amp; online orders', false)
            ->assertDontSee('Confirmed paid orders by delivery method')
            ->assertDontSee('Fully paid vs outstanding invoices')
            ->assertDontSee('data-report-region="delivery-methods"', false)
            ->assertDontSee('Open forecast →')->assertDontSee('Inventory needs attention')
            ->assertSee('Inventory attention')->assertSee('For packaging')
            ->assertSee('Counts fully paid invoices by payment date');
        Http::assertNothingSent();
    }

    public function test_operations_charts_apply_the_same_inclusive_cohort_and_keep_partial_invoices_out_of_delivery(): void
    {
        $pickup = $this->invoice(['created_at' => '2026-10-14 00:00:00']);
        $courier = $this->invoice(['created_at' => '2026-10-15 23:59:59', 'delivery_method' => 'lalamove',
            'status' => 'ready', 'walk_in_received_at' => null]);
        $partial = $this->invoice(['payment_status' => 'partially_paid', 'amount_paid' => 10000, 'paid_at' => null,
            'delivery_method' => 'lalamove', 'status' => 'ready']);
        $pending = $this->invoice(['payment_status' => 'pending', 'amount_paid' => 0, 'paid_at' => null, 'status' => 'awaiting_payment']);
        $this->invoice(['status' => 'cancelled']);
        $this->invoice(['payment_status' => 'pending', 'paid_at' => null, 'amount_paid' => 0, 'inventory_released' => true]);
        foreach (['2026-10-13 23:59:59', '2026-10-16 00:00:00', '2026-10-16 12:00:01'] as $outside) {
            $this->invoice(['created_at' => $outside]);
        }
        $response = $this->get(route('admin.operations', ['start_date' => '2026-10-14', 'end_date' => '2026-10-15']))->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['paid_orders'] === 3 && $stats['fulfillment_orders'] === 2
                && $stats['partially_paid'] === 1 && $stats['placed_orders'] === 6)
            ->assertViewHas('deliveryTotals', ['total' => 1, 'completed' => 0, 'active' => 1, 'attention' => 0, 'ready' => 1])
            ->assertViewHas('statusRows', fn ($rows) => array_column($rows, 'count') === [0, 1, 0, 1])
            ->assertSeeInOrder(['Delivery status at a glance', 'Confirmed paid orders', 'Current fulfillment status'])
            ->assertDontSee('Fully paid vs outstanding invoices')->assertDontSee('Confirmed paid orders by delivery method');
        $expected = ['packing' => [], 'ready' => [$courier->id], 'courier' => [], 'delivered' => [$pickup->id]];
        foreach ($response->viewData('statusRows') as $row) {
            $orders = $this->get($row['url'])->assertOk()->viewData('orders');
            $this->assertSame(count($expected[$row['status']]), $row['count']);
            $this->assertSame($row['count'], $orders->total());
            $this->assertEqualsCanonicalizing($expected[$row['status']], $orders->getCollection()->modelKeys());
        }
        Http::assertNothingSent();
    }

    public function test_annual_dashboard_excludes_older_paid_invoices_and_still_counts_legacy_methods_as_sales(): void
    {
        $this->invoice(['created_at' => '2020-01-01 10:00:00', 'paid_at' => '2020-01-02 10:00:00']);
        $this->invoice(['delivery_method' => 'legacy_courier']);
        $this->invoice(['created_at' => '2026-10-16 12:00:01', 'paid_at' => null, 'payment_status' => 'pending', 'amount_paid' => 0]);
        $this->get(route('admin.dashboard'))->assertOk()->assertViewHas('salesScope', 'Annual Sales · 2026')
            ->assertViewHas('ordersCount', 1)->assertViewHas('revenue', 20000)
            ->assertViewHas('salesChart', fn ($chart) => $chart['orders'] === 1)
            ->assertDontSee('Confirmed paid orders by delivery method');
        Http::assertNothingSent();
    }

    public function test_yearly_dashboard_explicit_dates_support_full_valid_ten_year_range_and_reject_one_day_beyond_it(): void
    {
        $old = $this->invoice(['created_at' => '2016-10-16 00:00:00', 'paid_at' => '2016-10-17 10:00:00']);
        $this->invoice(['created_at' => '2016-10-15 23:59:59', 'paid_at' => '2016-10-15 23:59:59']);
        $partial = $this->invoice(['created_at' => '2022-01-01 10:00:00', 'payment_status' => 'partially_paid',
            'amount_paid' => 5000, 'paid_at' => null]);
        $filters = ['period' => 'yearly', 'start_date' => '2016-10-16', 'end_date' => '2026-10-16'];
        $this->get(route('admin.dashboard', $filters))->assertOk()
            ->assertViewHas('ordersCount', 1)->assertViewHas('revenue', 20000)
            ->assertViewHas('salesChart', fn ($chart) => $chart['custom_range'] && $chart['orders'] === 1);
        $this->getJson(route('admin.orders', ['start_date' => '2016-10-15', 'end_date' => '2026-10-16']))
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        Http::assertNothingSent();
    }

    public function test_empty_count_charts_show_zero_counts_and_safe_percentages_without_changing_data(): void
    {
        $this->get(route('admin.dashboard'))->assertOk()->assertViewHas('ordersCount', 0)->assertViewHas('revenue', 0)
            ->assertSee('No confirmed walk-in or online orders in the last 4 months.')
            ->assertDontSee('Confirmed paid orders by delivery method')->assertDontSee('NaN')->assertDontSee('Infinity');
        $operations = $this->get(route('admin.operations'))->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['paid_orders'] === 0 && $stats['placed_orders'] === 0)
            ->assertSee('No confirmed paid orders were placed in these dates.')
            ->assertDontSee('Fully paid vs outstanding invoices')->assertDontSee('NaN')->assertDontSee('Infinity');
        foreach (array_merge($operations->viewData('deliveryRows'), $operations->viewData('statusRows')) as $row) {
            $this->assertSame(0, $row['count']);
            $this->assertEquals(0, $row['percentage']);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_chart_drilldown_count_is_not_limited_to_one_page_and_keeps_its_filters_on_next_page(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->invoice(['payment_status' => 'partially_paid', 'amount_paid' => 5000, 'paid_at' => null]);
        }
        $this->invoice(['payment_status' => 'partially_paid', 'status' => 'cancelled', 'amount_paid' => 5000, 'paid_at' => null]);
        $filters = ['start_date' => '2026-10-15', 'end_date' => '2026-10-15'];
        $this->get(route('admin.dashboard', $filters))->assertOk()
            ->assertViewHas('ordersCount', 0)->assertViewHas('revenue', 0);
        $orders = $this->get(route('admin.orders', $filters + ['exclude_cancelled' => 1, 'payment_status' => 'partially_paid']))
            ->assertOk()->viewData('orders');
        $this->assertSame(21, $orders->total());
        $this->assertSame(20, $orders->count());
        parse_str(parse_url($orders->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame($filters + ['exclude_cancelled' => '1', 'payment_status' => 'partially_paid', 'page' => '2'], $query);
        $this->get($orders->nextPageUrl())->assertOk()->assertViewHas('orders', fn ($page) => $page->total() === 21 && $page->count() === 1);
        $this->getJson(route('admin.orders', ['exclude_cancelled' => 'sometimes']))->assertUnprocessable()
            ->assertJsonValidationErrors('exclude_cancelled');
        Http::assertNothingSent();
    }
}
