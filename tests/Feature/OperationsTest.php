<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationsController;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Manila'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function login(string $role = 'staff'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    private function order(string $created = '2026-10-05 10:00:00', array $overrides = []): Order
    {
        return Order::create($overrides + [
            'number' => 'OPS-'.uniqid(), 'user_id' => User::factory()->create()->id,
            'tier' => 'retail', 'quantity' => 2, 'merchandise_base' => 20000, 'discount' => 0,
            'subtotal' => 20000, 'shipping' => 10000, 'total' => 30000,
            'status' => 'to_pack', 'payment_status' => 'paid', 'paid_at' => '2026-10-10 10:00:00',
            'recipient' => 'Buyer', 'phone' => '09123456789', 'address' => 'Store pickup',
            'delivery_method' => 'pickup', 'created_at' => $created, 'updated_at' => $created,
        ]);
    }

    private function refund(Order $order, array $overrides = []): Refund
    {
        return Refund::create($overrides + [
            'order_id' => $order->id, 'requested_by' => $order->user_id, 'requested_amount' => 9999, 'approved_amount' => 8888,
            'amount' => 6200, 'status' => 'succeeded', 'provider_refund_id' => 'ref_ops_'.uniqid(),
            'reason' => 'Test request', 'confirmed_at' => '2026-10-05 10:00:00',
            'created_at' => '2026-10-03 10:00:00', 'updated_at' => '2026-10-05 10:00:00',
        ]);
    }

    private function url(array $filters = []): string
    {
        return route('admin.operations', $filters + ['start_date' => '2026-10-01', 'end_date' => '2026-10-12']);
    }

    public function test_only_staff_and_admin_can_open_read_only_operations_dashboard(): void
    {
        $this->get(route('admin.operations'))->assertRedirect(route('admin.login'));
        $this->login('customer');
        $this->get(route('admin.operations'))->assertForbidden();
        foreach (['staff', 'admin'] as $role) {
            $this->login($role);
            $this->get(route('admin.operations'))->assertOk()->assertSee('Operations overview')
                ->assertSee('No verified or recorded refunds in this range')->assertSee('No confirmed paid orders were placed in these dates')
                ->assertViewHas('startDate', '2026-09-13')->assertViewHas('endDate', '2026-10-12')->assertViewHas('period', 'daily');
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_paid_fulfillment_counts_partition_orders_and_exclude_pending_payments(): void
    {
        $this->login();
        foreach (['to_pack', 'packing', 'ready', 'delivery_booking', 'delivery_attention', 'out_for_delivery', 'completed', 'legacy_dispatch'] as $i => $status) {
            $this->order(overrides: ['status' => $status, 'delivery_method' => in_array($i, [0, 2, 3, 5], true) ? 'lalamove' : 'pickup',
                'customer_confirmed_at' => $status === 'completed' ? '2026-10-11 10:00:00' : null]);
        }
        $this->order(overrides: ['status' => 'cancelled', 'delivery_method' => 'courier']);
        $this->order(overrides: ['payment_status' => 'pending', 'status' => 'awaiting_payment']);
        $this->order(overrides: ['payment_status' => 'pending', 'status' => 'to_pack']);
        $this->order(overrides: ['payment_status' => 'pending', 'status' => 'cancelled']);
        $this->order(overrides: ['payment_status' => 'failed', 'status' => 'cancelled']);
        $this->order(overrides: ['payment_status' => 'failed', 'status' => 'awaiting_payment']);
        $response = $this->get($this->url())->assertOk()->assertViewHas('stats', fn ($stats) => $stats['paid_orders'] === 9 && $stats['paid_pieces'] === 18
            && $stats['placed_orders'] === 14 && $stats['pickup'] === 4 && $stats['lalamove'] === 4 && $stats['other_delivery'] === 1
            && $stats['fulfillment_orders'] === 8 && $stats['cancelled_paid'] === 1
            && $stats['unpaid_pending'] === 2 && $stats['cancelled_unpaid'] === 2 && $stats['other_unpaid'] === 1)
            ->assertViewHas('statusRows', fn ($rows) => array_column($rows, 'count') === [2, 4, 1, 1])
            ->assertViewHas('chart', fn ($rows) => array_sum(array_column($rows, 'orders')) === 9)
            ->assertSee('Each order appears in exactly one stage')->assertSee('Current order status as of')
            ->assertSee('cancelled paid orders are excluded from fulfillment stages');
        $rows = collect($response->viewData('statusRows'))->keyBy('status');
        foreach (OperationsController::STATUSES as $status => $label) {
            parse_str(parse_url($rows[$status]['url'], PHP_URL_QUERY), $filters);
            $this->assertSame(['start_date' => '2026-10-01', 'end_date' => '2026-10-12', 'fulfillment' => $status, 'payment_status' => 'paid'], $filters);
            $orders = $this->get($rows[$status]['url'])->assertOk()->viewData('orders');
            $this->assertSame($rows[$status]['count'], $orders->total());
            $this->assertTrue($orders->getCollection()->every(fn ($order) => $order->status !== 'cancelled' && $order->payment_status === 'paid'));
        }
        $this->assertSame(14, Order::count());
        Http::assertNothingSent();
    }

    public function test_order_date_boundaries_are_inclusive_and_today_does_not_include_future_records(): void
    {
        $this->login();
        $this->order('2026-09-30 23:59:59');
        $this->order('2026-10-01 00:00:00', ['paid_at' => '2026-10-11 10:00:00']);
        $this->order('2026-10-11 23:59:59');
        $this->order('2026-10-12 12:00:00');
        $this->order('2026-10-12 12:00:01');
        $this->order('2026-10-13 00:00:00');
        $this->get($this->url())->assertOk()->assertViewHas('stats', fn ($stats) => $stats['paid_orders'] === 3)
            ->assertViewHas('chart', fn ($rows) => count($rows) === 12 && $rows[0]['orders'] === 1 && $rows[10]['orders'] === 1 && $rows[11]['orders'] === 1);
        $this->get($this->url(['end_date' => '2026-10-11']))->assertViewHas('stats', fn ($stats) => $stats['paid_orders'] === 2);
    }

    public function test_delivery_rows_partition_paid_lalamove_orders_and_links_return_the_exact_cohort(): void
    {
        $this->login();
        $expected = ['packing' => [], 'ready' => [], 'courier' => [], 'delivered' => []];
        $scenarios = [
            ['to_pack', 'packing', []], ['packing', 'packing', []], ['ready', 'ready', []],
            ['delivery_booking', 'ready', []], ['delivery_attention', 'ready', []], ['out_for_delivery', 'courier', []],
            ['completed', 'courier', ['provider_completed_at' => '2026-10-11 10:00:00']],
            ['completed', 'delivered', ['customer_confirmed_at' => '2026-10-11 10:00:00']],
            ['awaiting_payment', 'ready', []], ['legacy_dispatch', 'ready', []],
            ['ready', 'ready', ['customer_confirmed_at' => '2026-10-11 10:00:00']],
        ];
        foreach ($scenarios as [$status, $stage, $attributes]) {
            $expected[$stage][] = $this->order(overrides: $attributes + ['status' => $status, 'delivery_method' => 'lalamove'])->id;
            $this->order(overrides: $attributes + ['status' => $status, 'delivery_method' => 'pickup']);
        }
        $this->order(overrides: ['status' => 'cancelled', 'delivery_method' => 'lalamove']);
        $this->order(overrides: ['status' => 'ready', 'delivery_method' => 'courier']);
        $this->order(overrides: ['status' => 'ready', 'delivery_method' => 'lalamove', 'payment_status' => 'pending']);
        $this->order(overrides: ['status' => 'completed', 'delivery_method' => 'lalamove', 'payment_status' => 'failed']);
        $this->order('2026-09-30 23:59:59', ['status' => 'ready', 'delivery_method' => 'lalamove']);
        $this->order('2026-10-12 12:00:01', ['status' => 'ready', 'delivery_method' => 'lalamove']);
        $this->order('2026-10-13 00:00:00', ['status' => 'ready', 'delivery_method' => 'lalamove']);
        $response = $this->get($this->url())->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['lalamove'] === 12 && $stats['paid_orders'] === 24 && $stats['cancelled_paid_lalamove'] === 1)
            ->assertViewHas('deliveryTotals', ['total' => 11, 'completed' => 1, 'active' => 10, 'attention' => 2, 'ready' => 6])
            ->assertViewHas('statusRows', fn ($rows) => count($rows) === 4 && array_sum(array_column($rows, 'count')) === 23)
            ->assertViewHas('chart', fn ($rows) => array_sum(array_column($rows, 'orders')) === 24)
            ->assertSee('2 orders in Ready for delivery need a booking check')
            ->assertSee('cancelled paid delivery orders are excluded from these four stages')
            ->assertDontSee('Other status')->assertDontSee('Being packed')->assertDontSee('Booking being checked')
            ->assertSee('Customer confirmed receipt and submitted a review');
        $rows = collect($response->viewData('deliveryRows'))->keyBy('status');
        $this->assertSame(['Packing', 'Ready for delivery', 'With courier', 'Delivered'], $rows->pluck('label')->all());
        $this->assertSame([
            'Payment is confirmed. Pack the clothing before arranging a courier.',
            'Packing is complete. Arrange a courier booking for this order.',
            'A courier booking is active. Open the order to check its latest Lalamove status.',
            'The order is marked delivered. Open it to review the delivery details.',
        ], $rows->pluck('description')->all());
        $this->assertSame(11, $rows->sum('count'));
        $this->assertEqualsWithDelta(100, $rows->sum('percentage'), .00001);
        foreach ($expected as $status => $ids) {
            $row = $rows[$status];
            $this->assertSame(count($ids), $row['count']);
            $this->assertEqualsWithDelta(count($ids) / 11 * 100, $row['percentage'], .00001);
            $this->assertNotEmpty($row['description']);
            $this->assertMatchesRegularExpression('/^#[a-f0-9]{6}$/i', $row['color']);
            parse_str(parse_url($row['url'], PHP_URL_QUERY), $filters);
            $this->assertSame(['start_date' => '2026-10-01', 'end_date' => '2026-10-12', 'fulfillment' => $status,
                'payment_status' => 'paid', 'delivery_method' => 'lalamove'], $filters);
            $orders = $this->get($row['url'])->assertOk()->viewData('orders');
            $this->assertSame(count($ids), $orders->total());
            $this->assertEqualsCanonicalizing($ids, $orders->getCollection()->modelKeys());
        }
        Http::assertNothingSent();
    }

    public function test_delivery_rows_show_current_status_of_orders_placed_in_range_not_historical_delivery_events(): void
    {
        $this->login();
        $this->order('2026-09-30 10:00:00', ['delivery_method' => 'lalamove', 'status' => 'completed', 'updated_at' => '2026-10-03 10:00:00']);
        $placedInRange = $this->order('2026-10-02 10:00:00', ['delivery_method' => 'lalamove', 'status' => 'out_for_delivery']);
        $placedInRange->update(['provider_completed_at' => '2026-10-12 09:00:00', 'delivery_last_status' => 'COMPLETED']);
        $this->get($this->url(['end_date' => '2026-10-05']))->assertOk()
            ->assertViewHas('deliveryTotals', ['total' => 1, 'completed' => 0, 'active' => 1, 'attention' => 0, 'ready' => 0])
            ->assertViewHas('deliveryRows', fn ($rows) => collect($rows)->firstWhere('status', 'courier')['count'] === 1
                && collect($rows)->firstWhere('status', 'delivered')['count'] === 0);
        $placedInRange->update(['status' => 'completed', 'customer_confirmed_at' => '2026-10-12 10:00:00']);
        $response = $this->get($this->url(['end_date' => '2026-10-05']))->assertOk()
            ->assertViewHas('deliveryTotals', ['total' => 1, 'completed' => 1, 'active' => 0, 'attention' => 0, 'ready' => 0]);
        $rows = collect($response->viewData('deliveryRows'))->keyBy('status');
        $this->assertSame(1, $rows['delivered']['count']);
        $this->assertSame(0, $rows['courier']['count']);
        $this->assertSame(100, $rows['delivered']['percentage']);
        $this->get($rows['delivered']['url'])->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 1 && $orders->first()->id === $placedInRange->id);
    }

    public function test_empty_delivery_cohort_has_zero_rows_without_using_pickup_or_unpaid_orders(): void
    {
        $this->login();
        $this->order(overrides: ['status' => 'ready', 'delivery_method' => 'pickup']);
        $this->order(overrides: ['status' => 'ready', 'delivery_method' => 'lalamove', 'payment_status' => 'pending']);
        $response = $this->get($this->url())->assertOk()
            ->assertViewHas('deliveryTotals', ['total' => 0, 'completed' => 0, 'active' => 0, 'attention' => 0, 'ready' => 0])
            ->assertViewHas('deliveryRows', fn ($rows) => count($rows) === 4 && collect($rows)->every(fn ($row) => $row['count'] === 0 && $row['percentage'] === 0))
            ->assertViewHas('stats', fn ($stats) => $stats['paid_orders'] === 1 && $stats['unpaid_pending'] === 1);
        $row = collect($response->viewData('deliveryRows'))->firstWhere('status', 'ready');
        $this->get($row['url'])->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 0);
        Http::assertNothingSent();
    }

    public function test_orders_delivery_filter_combines_with_other_filters_and_survives_pagination(): void
    {
        $this->login();
        $buyer = User::factory()->create();
        foreach (['pickup', 'lalamove'] as $method) {
            for ($i = 0; $i < 21; $i++) {
                $this->order(overrides: ['number' => 'OPS-DELIVERY-'.$method.'-'.$i, 'user_id' => $buyer->id,
                    'status' => ['ready', 'delivery_booking', 'delivery_attention'][$i % 3], 'delivery_method' => $method]);
            }
        }
        $this->order(overrides: ['number' => 'OPS-DELIVERY-unpaid', 'status' => 'ready', 'delivery_method' => 'lalamove', 'payment_status' => 'pending']);
        $this->order(overrides: ['number' => 'OPS-DELIVERY-packing', 'status' => 'packing', 'delivery_method' => 'lalamove']);
        $this->order('2026-09-30 10:00:00', ['number' => 'OPS-DELIVERY-older', 'status' => 'ready', 'delivery_method' => 'pickup']);
        foreach (['pickup', 'lalamove'] as $method) {
            $filters = ['delivery_method' => $method, 'fulfillment' => 'ready', 'payment_status' => 'paid', 'q' => 'OPS-DELIVERY-',
                'start_date' => '2026-10-01', 'end_date' => '2026-10-12'];
            $response = $this->get(route('admin.orders', $filters))->assertOk()
                ->assertSee('Delivery method')->assertSee('name="delivery_method"', false)
                ->assertSee('<option value="'.$method.'" selected>', false)
                ->assertViewHas('orders', fn ($orders) => $orders->total() === 21 && $orders->count() === 20
                    && $orders->getCollection()->every(fn ($order) => $order->delivery_method === $method
                        && in_array($order->status, ['ready', 'delivery_booking', 'delivery_attention'], true) && $order->payment_status === 'paid'));
            $next = $response->viewData('orders')->nextPageUrl();
            parse_str(parse_url($next, PHP_URL_QUERY), $query);
            foreach ($filters as $field => $value) {
                $this->assertSame($value, $query[$field]);
            }
            $this->assertSame('2', $query['page']);
            $this->get($next)->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 21 && $orders->count() === 1 && $orders->first()->delivery_method === $method);
        }
        Http::assertNothingSent();
    }

    public function test_cancelled_paid_orders_stay_in_financial_totals_without_creating_a_fifth_fulfillment_stage(): void
    {
        $this->login();
        $this->order(overrides: ['status' => 'cancelled', 'delivery_method' => 'lalamove']);
        $response = $this->get($this->url())->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['paid_orders'] === 1 && $stats['fulfillment_orders'] === 0
                && $stats['cancelled_paid'] === 1 && $stats['cancelled_paid_lalamove'] === 1)
            ->assertViewHas('deliveryTotals', ['total' => 0, 'completed' => 0, 'active' => 0, 'attention' => 0, 'ready' => 0])
            ->assertViewHas('deliveryRows', fn ($rows) => count($rows) === 4 && collect($rows)->every(fn ($row) => $row['count'] === 0 && $row['percentage'] === 0))
            ->assertViewHas('statusRows', fn ($rows) => count($rows) === 4 && collect($rows)->every(fn ($row) => $row['count'] === 0 && $row['percentage'] === 0))
            ->assertViewHas('chart', fn ($rows) => array_sum(array_column($rows, 'orders')) === 1)
            ->assertSee('No paid orders are eligible for fulfillment in these dates.')
            ->assertDontSee('NaN')->assertDontSee('Infinity');
        foreach ($response->viewData('deliveryRows') as $row) {
            $this->get($row['url'])->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 0);
        }
        Http::assertNothingSent();
    }

    public function test_orders_reject_unsupported_delivery_methods_and_accept_an_empty_filter(): void
    {
        $this->login();
        foreach (['courier', 'pickup,lalamove', 'LALAMOVE', ['pickup']] as $method) {
            $this->getJson(route('admin.orders', ['delivery_method' => $method]))->assertUnprocessable()->assertJsonValidationErrors('delivery_method');
        }
        $this->order(overrides: ['delivery_method' => 'pickup']);
        $this->order(overrides: ['delivery_method' => 'lalamove']);
        $this->get(route('admin.orders', ['delivery_method' => '']))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 2);
    }

    public function test_refunds_use_actual_confirmed_amount_and_confirmation_date_without_deducting_paid_orders(): void
    {
        $this->login();
        $older = $this->order('2026-09-01 10:00:00', ['status' => 'completed', 'paid_at' => '2026-09-01 10:00:00', 'customer_confirmed_at' => '2026-09-02 10:00:00']);
        $current = $this->order('2026-10-04 10:00:00', ['status' => 'completed', 'paid_at' => '2026-10-04 10:00:00', 'customer_confirmed_at' => '2026-10-04 11:00:00']);
        $this->refund($older);
        $this->refund($older, ['amount' => 3800]);
        $this->refund($current, ['amount' => 1000, 'confirmed_at' => '2026-10-06 10:00:00']);
        foreach ([['amount' => null], ['amount' => 0], ['provider_refund_id' => null], ['provider_refund_id' => ''], ['confirmed_at' => null],
            ['confirmed_at' => '2026-09-30 23:59:59'], ['confirmed_at' => '2026-10-12 12:00:01']] as $invalid) {
            $this->refund($older, $invalid);
        }
        foreach (['requested', 'approved', 'pending', 'processing', 'rejected', 'failed'] as $status) {
            $this->refund($older, ['status' => $status]);
        }
        $this->refund($older, ['status' => 'requested', 'created_at' => '2026-09-30 23:59:59']);
        $this->refund($older, ['status' => 'requested', 'created_at' => '2026-10-12 12:00:01']);
        $this->get($this->url())->assertOk()->assertViewHas('stats', fn ($stats) => $stats['paid_orders'] === 1
            && $stats['confirmed_refunds'] === 3 && $stats['refund_amount'] === 11000 && $stats['open_refund_requests'] === 4)
            ->assertViewHas('statusRows', fn ($rows) => collect($rows)->firstWhere('status', 'delivered')['count'] === 1 && array_sum(array_column($rows, 'count')) === 1)
            ->assertViewHas('chart', fn ($rows) => $rows[4]['refunds'] === 2 && $rows[4]['refund_amount'] === 10000 && $rows[5]['refunds'] === 1
                && array_sum(array_column($rows, 'refunds')) === 3 && array_sum(array_column($rows, 'orders')) === 1)
            ->assertSee('₱110.00')->assertSee('may include delivery fees')->assertSee('do not automatically reduce Confirmed Clothing sale');
        $this->assertSame('paid', $current->fresh()->payment_status);
        $this->assertSame(20000, $current->fresh()->subtotal);
        Http::assertNothingSent();
    }

    public function test_monthly_chart_zero_fills_and_clips_partial_months_to_selected_dates(): void
    {
        $this->login();
        $this->order('2026-07-14 23:59:59');
        $this->order('2026-07-15 00:00:00');
        $this->order('2026-09-01 00:00:00');
        $this->order('2026-10-05 23:59:59');
        $this->order('2026-10-06 00:00:00');
        $this->get($this->url(['start_date' => '2026-07-15', 'end_date' => '2026-10-05', 'period' => 'monthly']))->assertOk()
            ->assertViewHas('chart', fn ($rows) => array_column($rows, 'orders') === [1, 0, 1, 1]
                && $rows[0]['range'] === 'Jul 15, 2026 – Jul 31, 2026' && $rows[3]['range'] === 'Oct 1, 2026 – Oct 5, 2026')
            ->assertSee('<svg class="operations-chart"', false)->assertSee('operations-trend-description')->assertSee('View exact order and refund figures');
        $this->get($this->url(['start_date' => '2026-07-15', 'end_date' => '2026-10-05']))->assertViewHas('period', 'monthly');
    }

    public function test_default_range_is_based_on_asia_manila_calendar_even_when_clock_is_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 16:15:00', 'UTC'));
        $this->login();
        $this->order('2026-10-11 23:59:59');
        $this->order('2026-10-12 00:00:00');
        $this->get(route('admin.operations'))->assertOk()->assertViewHas('endDate', '2026-10-12')->assertViewHas('startDate', '2026-09-13')
            ->assertViewHas('chart', fn ($rows) => $rows[28]['orders'] === 1 && $rows[29]['orders'] === 1);
    }

    public static function invalidDateRanges(): array
    {
        return [[['start_date' => '2026-02-30'], 'start_date'], [['end_date' => '2026-10-13'], 'end_date'],
            [['start_date' => '2026-10-13'], 'start_date'], [['start_date' => '2026-10-11', 'end_date' => '2026-10-10'], 'end_date'],
            [['start_date' => '2025-10-12', 'end_date' => '2026-10-13'], 'end_date'],
            [['start_date' => '2025-10-11', 'end_date' => '2026-10-12'], 'end_date'], [['period' => 'yearly'], 'period']];
    }

    #[DataProvider('invalidDateRanges')]
    public function test_invalid_future_reversed_and_overlong_date_ranges_are_rejected(array $filters, string $field): void
    {
        $this->login();
        $this->getJson($this->url($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_maximum_366_day_range_and_end_only_filters_are_supported(): void
    {
        $this->login();
        $this->get($this->url(['start_date' => '2025-10-12', 'end_date' => '2026-10-12', 'period' => 'daily']))->assertOk()
            ->assertViewHas('chart', fn ($rows) => count($rows) === 366);
        $this->get(route('admin.operations', ['end_date' => '2026-09-30']))->assertOk()
            ->assertViewHas('startDate', '2026-09-01')->assertViewHas('endDate', '2026-09-30');
    }
}
