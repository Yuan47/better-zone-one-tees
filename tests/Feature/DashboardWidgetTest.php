<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'Asia/Manila'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function invoice(array $attributes = []): Order
    {
        $subtotal = $attributes['subtotal'] ?? 10000;
        $shipping = $attributes['shipping'] ?? 0;
        $total = $attributes['total'] ?? $subtotal + $shipping;
        $paid = ($attributes['payment_status'] ?? 'pending') === 'paid';

        return Order::create($attributes + [
            'number' => 'WIDGET-'.Str::random(12), 'user_id' => null, 'recipient' => 'Widget fixture customer',
            'phone' => '09000000000', 'address' => 'Store pickup', 'delivery_method' => 'pickup', 'sales_channel' => 'walk_in',
            'tier' => 'retail', 'quantity' => 1, 'merchandise_base' => $subtotal, 'discount' => 0,
            'subtotal' => $subtotal, 'shipping' => $shipping, 'total' => $total,
            'payment_status' => 'pending', 'amount_paid' => $paid ? $total : 0,
            'paid_at' => $paid ? '2026-10-25 11:00:00' : null, 'status' => $paid ? 'packing' : 'awaiting_payment',
            'created_at' => '2026-10-20 10:00:00', 'updated_at' => '2026-10-20 10:00:00',
        ]);
    }

    public function test_outstanding_cards_count_registered_customers_once_and_include_guest_balances_without_inventing_guest_people(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $second = User::factory()->create(['role' => 'customer']);
        $first = $this->invoice(['user_id' => $customer->id]);
        $deposit = $this->invoice(['user_id' => $customer->id, 'payment_status' => 'partially_paid', 'total' => 20000, 'amount_paid' => 5000]);
        $other = $this->invoice(['user_id' => $second->id, 'payment_status' => 'partially_paid', 'total' => 30000, 'amount_paid' => 10000]);
        $guestDeposit = $this->invoice(['payment_status' => 'partially_paid', 'total' => 40000, 'amount_paid' => 25000]);
        $guest = $this->invoice(['total' => 5000]);
        foreach ([['status' => 'cancelled'], ['inventory_released' => true], ['created_at' => '2026-10-25 12:00:01'],
            ['payment_status' => 'paid', 'amount_paid' => 0], ['payment_status' => 'failed'],
            ['payment_status' => 'partially_paid', 'amount_paid' => 10000],
            ['payment_status' => 'partially_paid', 'amount_paid' => 20000], ['total' => 0]] as $excluded) {
            $this->invoice($excluded + ['user_id' => $second->id]);
        }
        $before = Order::orderBy('id')->get()->map->getAttributes()->all();
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $response = $this->get('/admin?start_date=2026-09-01&end_date=2026-09-30')->assertOk()
            ->assertSee('2 guest invoices tracked separately')->assertDontSee('@if(', false);
        $stats = $response->viewData('widgetStats');

        $this->assertSame(2, $stats['outstanding_customers']);
        $this->assertSame(2, $stats['guest_outstanding_invoices']);
        $this->assertSame(5, $stats['outstanding_invoices']);
        $this->assertSame(65000, $stats['outstanding_payment']);
        $this->assertSame(0, $response->viewData('revenue'));
        $orders = $this->get($response->viewData('widgetLinks')['outstanding_payment'])->assertOk()->viewData('orders');
        $this->assertEqualsCanonicalizing([$first->id, $deposit->id, $other->id, $guestDeposit->id, $guest->id], $orders->pluck('id')->all());
        $this->assertSame($before, Order::orderBy('id')->get()->map->getAttributes()->all());
        Http::assertNothingSent();
    }

    public function test_on_delivery_counts_current_courier_stages_and_links_to_exactly_the_same_orders(): void
    {
        $courier = $this->invoice(['payment_status' => 'paid', 'status' => 'out_for_delivery', 'delivery_method' => 'lalamove', 'created_at' => '2025-01-01 10:00:00']);
        $awaitingReceipt = $this->invoice(['payment_status' => 'paid', 'status' => 'completed', 'delivery_method' => 'lalamove',
            'provider_completed_at' => '2026-10-25 10:00:00']);
        foreach ([['status' => 'packing'], ['status' => 'ready'], ['status' => 'delivery_attention'],
            ['status' => 'completed', 'customer_confirmed_at' => '2026-10-25 11:00:00'],
            ['status' => 'completed', 'walk_in_received_at' => '2026-10-25 11:00:00'],
            ['status' => 'cancelled'], ['status' => 'out_for_delivery', 'inventory_released' => true],
            ['status' => 'out_for_delivery', 'created_at' => '2026-10-25 12:00:01'],
            ['status' => 'out_for_delivery', 'payment_status' => 'pending'],
            ['status' => 'out_for_delivery', 'payment_status' => 'partially_paid']] as $excluded) {
            $this->invoice($excluded + ['payment_status' => 'paid', 'delivery_method' => 'lalamove']);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->get('/admin?start_date=2026-09-01&end_date=2026-09-30')->assertOk();

        $this->assertSame(2, $response->viewData('widgetStats')['on_delivery']);
        $orders = $this->get($response->viewData('widgetLinks')['on_delivery'])->assertOk()->viewData('orders');
        $this->assertEqualsCanonicalizing([$courier->id, $awaitingReceipt->id], $orders->pluck('id')->all());
        Http::assertNothingSent();
    }

    public function test_current_month_sales_follow_manila_paid_dates_and_clothing_amounts_independently_of_the_selected_chart_period(): void
    {
        $start = $this->invoice(['payment_status' => 'paid', 'subtotal' => 10000, 'shipping' => 2500, 'merchandise_base' => 11000,
            'discount' => 1000, 'paid_at' => '2026-10-01 00:00:00', 'created_at' => '2026-09-30 12:00:00']);
        $this->invoice(['payment_status' => 'paid', 'subtotal' => 20000, 'shipping' => 2500, 'paid_at' => '2026-10-25 12:00:00']);
        $this->invoice(['payment_status' => 'paid', 'subtotal' => 70000, 'paid_at' => '2026-09-30 23:59:59', 'created_at' => '2026-09-30 12:00:00']);
        foreach ([['paid_at' => '2026-10-25 12:00:01'], ['paid_at' => '2026-11-01 00:00:00'],
            ['paid_at' => null], ['payment_status' => 'pending', 'paid_at' => '2026-10-25 11:00:00'],
            ['payment_status' => 'partially_paid', 'paid_at' => '2026-10-25 11:00:00']] as $excluded) {
            $this->invoice($excluded + ['payment_status' => 'paid', 'subtotal' => 90000]);
        }
        $start->refunds()->create(['requested_by' => null, 'requested_amount' => 5000, 'approved_amount' => 5000,
            'amount' => 5000, 'status' => 'succeeded', 'provider_refund_id' => 'ref_widget_month',
            'reason' => 'Confirmed fixture refund', 'confirmed_at' => '2026-10-25 11:00:00']);
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $response = $this->get('/admin?start_date=2026-09-01&end_date=2026-09-30')->assertOk();
        $stats = $response->viewData('widgetStats');

        $this->assertSame(30000, $stats['month_sales']);
        $this->assertSame('October 2026', $stats['month_label']);
        $this->assertSame('2026-10-25T12:00:00+08:00', $stats['as_of']);
        $this->assertSame(70000, $response->viewData('revenue'));
        $link = $response->viewData('widgetLinks')['month_sales'];
        parse_str(parse_url($link, PHP_URL_QUERY), $filters);
        $this->assertSame(['period' => 'daily', 'start_date' => '2026-10-01', 'end_date' => '2026-10-25'], $filters);
        $this->assertSame('reports', parse_url($link, PHP_URL_FRAGMENT));
        $this->get(Str::before($link, '#'))->assertOk()->assertViewHas('revenue', 30000)->assertViewHas('ordersCount', 2);
        Http::assertNothingSent();
    }

    public function test_outstanding_values_are_clamped_per_invoice_without_netting_overpayments_against_other_customers(): void
    {
        $this->invoice(['total' => 10000, 'amount_paid' => -500]);
        $this->invoice(['payment_status' => 'partially_paid', 'total' => 20000, 'amount_paid' => 5000]);
        $this->invoice(['payment_status' => 'partially_paid', 'total' => 10000, 'amount_paid' => 50000]);
        $this->actingAs(User::factory()->create(['role' => 'staff']));

        $this->get('/admin')->assertOk()->assertViewHas('widgetStats', fn ($stats) => $stats['outstanding_payment'] === 25000
            && $stats['outstanding_invoices'] === 2 && $stats['outstanding_customers'] === 0 && $stats['guest_outstanding_invoices'] === 2);
        Http::assertNothingSent();
    }

    public function test_widget_data_is_available_to_both_workspace_roles_and_empty_data_is_zero_safe(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get('/admin')->assertForbidden();
        foreach (['staff', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/admin')->assertOk()->assertDontSee('guest invoices tracked separately')->assertDontSee('@if(', false)
                ->assertViewHas('widgetStats', fn ($stats) => $stats['on_delivery'] === 0
                && $stats['outstanding_customers'] === 0 && $stats['guest_outstanding_invoices'] === 0
                && $stats['outstanding_payment'] === 0 && $stats['month_sales'] === 0);
        }
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }
}
