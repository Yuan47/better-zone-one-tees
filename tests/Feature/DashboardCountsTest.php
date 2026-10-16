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

    private function assertDrilldowns(array $rows, array $expectedIds): void
    {
        foreach ($rows as $row) {
            $key = $row['status'] ?? $row['method'];
            $orders = $this->get($row['url'])->assertOk()->viewData('orders');
            $this->assertSame(count($expectedIds[$key]), $row['count']);
            $this->assertSame($row['count'], $orders->total());
            $this->assertSame(collect($expectedIds[$key])->sort()->values()->all(),
                $orders->getCollection()->pluck('id')->sort()->values()->all());
            parse_str(parse_url($row['url'], PHP_URL_QUERY), $query);
            $this->assertSame('1', $query['exclude_cancelled']);
        }
    }

    public function test_dashboard_count_charts_use_placed_dates_and_exact_drilldowns_without_changing_paid_date_sales(): void
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

        $response = $this->get(route('admin.dashboard', $filters))->assertOk()
            ->assertViewHas('paymentTotal', 4)->assertViewHas('methodTotal', 2)->assertViewHas('otherPaymentCount', 1)
            ->assertViewHas('ordersCount', 3)->assertViewHas('revenue', 110000)
            ->assertSee('Fully paid vs outstanding invoices')->assertSee('Confirmed paid orders by delivery method')
            ->assertSee('this comparison uses the date the order was placed')
            ->assertSee('Deposits remain outstanding until full settlement');
        $this->assertDrilldowns($response->viewData('paymentRows'), [
            'paid' => [$pickup->id, $courier->id], 'partially_paid' => [$partial->id], 'pending' => [$pending->id],
        ]);
        $this->assertDrilldowns($response->viewData('methodRows'), ['pickup' => [$pickup->id], 'lalamove' => [$courier->id]]);
        $this->assertSame([50.0, 25.0, 25.0], array_column($response->viewData('paymentRows'), 'percentage'));
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
            ->assertViewHas('paymentTotal', 4)->assertViewHas('methodTotal', 2)
            ->assertViewHas('deliveryTotals', ['total' => 1, 'completed' => 0, 'active' => 1, 'attention' => 0, 'ready' => 1])
            ->assertViewHas('statusRows', fn ($rows) => array_column($rows, 'count') === [0, 1, 0, 1])
            ->assertSeeInOrder(['Delivery status at a glance', 'Confirmed paid orders', 'Fully paid vs outstanding invoices']);
        $this->assertDrilldowns($response->viewData('paymentRows'), [
            'paid' => [$pickup->id, $courier->id], 'partially_paid' => [$partial->id], 'pending' => [$pending->id],
        ]);
        $this->assertDrilldowns($response->viewData('methodRows'), ['pickup' => [$pickup->id], 'lalamove' => [$courier->id]]);
        Http::assertNothingSent();
    }

    public function test_all_time_dashboard_counts_include_older_invoices_and_report_legacy_methods_without_inventing_a_chart_category(): void
    {
        $old = $this->invoice(['created_at' => '2020-01-01 10:00:00', 'paid_at' => '2020-01-02 10:00:00']);
        $legacy = $this->invoice(['delivery_method' => 'legacy_courier']);
        $this->invoice(['created_at' => '2026-10-16 12:00:01', 'paid_at' => null, 'payment_status' => 'pending', 'amount_paid' => 0]);
        $response = $this->get(route('admin.dashboard'))->assertOk()->assertViewHas('invoiceScope', 'All time')
            ->assertViewHas('paymentTotal', 2)->assertViewHas('methodTotal', 1)->assertViewHas('otherMethodCount', 1)
            ->assertSee('fully paid orders use another delivery method and are excluded')
            ->assertViewHas('salesChart', fn ($chart) => $chart['orders'] === 1);
        $this->assertDrilldowns($response->viewData('paymentRows'), ['paid' => [$old->id, $legacy->id], 'partially_paid' => [], 'pending' => []]);
        $this->assertDrilldowns($response->viewData('methodRows'), ['pickup' => [$old->id], 'lalamove' => []]);
        Http::assertNothingSent();
    }

    public function test_yearly_dashboard_drilldowns_support_the_full_valid_ten_year_range_and_reject_one_day_beyond_it(): void
    {
        $old = $this->invoice(['created_at' => '2016-10-16 00:00:00', 'paid_at' => '2016-10-17 10:00:00']);
        $this->invoice(['created_at' => '2016-10-15 23:59:59', 'paid_at' => '2016-10-15 23:59:59']);
        $partial = $this->invoice(['created_at' => '2022-01-01 10:00:00', 'payment_status' => 'partially_paid',
            'amount_paid' => 5000, 'paid_at' => null]);
        $filters = ['period' => 'yearly', 'start_date' => '2016-10-16', 'end_date' => '2026-10-16'];
        $response = $this->get(route('admin.dashboard', $filters))->assertOk()->assertViewHas('paymentTotal', 2);
        $this->assertDrilldowns($response->viewData('paymentRows'), ['paid' => [$old->id], 'partially_paid' => [$partial->id], 'pending' => []]);
        $this->assertDrilldowns($response->viewData('methodRows'), ['pickup' => [$old->id], 'lalamove' => []]);
        $this->getJson(route('admin.orders', ['start_date' => '2016-10-15', 'end_date' => '2026-10-16']))
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        Http::assertNothingSent();
    }

    public function test_empty_count_charts_show_zero_counts_and_safe_percentages_without_changing_data(): void
    {
        foreach (['admin.dashboard', 'admin.operations'] as $route) {
            $response = $this->get(route($route))->assertOk()->assertViewHas('paymentTotal', 0)->assertViewHas('methodTotal', 0)
                ->assertSee('No paid, partially paid or unpaid invoices in these placed dates')
                ->assertSee('No fully paid pickup or Lalamove orders in these placed dates');
            foreach (array_merge($response->viewData('paymentRows'), $response->viewData('methodRows')) as $row) {
                $this->assertSame(0, $row['count']);
                $this->assertEquals(0, $row['percentage']);
            }
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
        $response = $this->get(route('admin.dashboard', $filters))->assertOk()->assertViewHas('paymentTotal', 21)
            ->assertViewHas('ordersCount', 0)->assertViewHas('revenue', 0);
        $partial = collect($response->viewData('paymentRows'))->firstWhere('status', 'partially_paid');
        $orders = $this->get($partial['url'])->assertOk()->viewData('orders');
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
