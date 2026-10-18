<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use App\Services\Forecast;
use App\Services\SalesReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartialReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'Asia/Manila'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function staff(): User
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff);

        return $staff;
    }

    private function invoice(array $attributes = []): Order
    {
        $subtotal = $attributes['subtotal'] ?? 20000;
        $shipping = $attributes['shipping'] ?? 10000;
        $payment = $attributes['payment_status'] ?? 'paid';
        $walkInReceived = $payment === 'paid' && ($attributes['sales_channel'] ?? 'walk_in') === 'walk_in';

        return Order::create($attributes + [
            'number' => 'PARTIAL-'.uniqid(), 'user_id' => null, 'recipient' => 'Guest recipient', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'sales_channel' => 'walk_in',
            'tier' => 'retail', 'quantity' => 2, 'merchandise_base' => $subtotal, 'discount' => 0,
            'subtotal' => $subtotal, 'shipping' => $shipping, 'total' => $subtotal + $shipping,
            'payment_status' => $payment, 'amount_paid' => $payment === 'paid' ? $subtotal + $shipping : 0,
            'paid_at' => $payment === 'paid' ? '2026-10-15 10:00:00' : null,
            'status' => $walkInReceived ? 'completed' : ($payment === 'paid' ? 'packing' : 'awaiting_payment'), 'due_at' => null,
            'walk_in_received_at' => $walkInReceived ? ($attributes['paid_at'] ?? '2026-10-15 10:00:00') : null,
            'created_at' => '2026-10-14 09:00:00', 'updated_at' => '2026-10-14 09:00:00',
        ]);
    }

    private function variant(string $name): Variant
    {
        $product = Product::create(['name' => $name, 'slug' => 'partial-'.uniqid(), 'category' => '',
            'description' => 'Reporting fixture', 'active' => true, 'created_at' => '2026-07-01 00:00:00']);

        return $product->variants()->create(['sku' => 'PARTIAL-'.uniqid(), 'size' => 'M', 'color' => 'White',
            'stock' => 100, 'reserved' => 0, 'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
    }

    private function items(Order $order, Variant $variant): void
    {
        $order->items()->create(['variant_id' => $variant->id, 'name' => $variant->product->name,
            'size' => $variant->size, 'color' => $variant->color, 'quantity' => $order->quantity,
            'unit_price' => (int) ($order->subtotal / $order->quantity), 'total' => $order->subtotal]);
    }

    public function test_guest_cash_and_online_settlements_count_but_deposits_do_not_enter_sales_bestsellers_or_csv(): void
    {
        $this->staff();
        $cash = $this->invoice(['recipient' => '=1+1', 'payment_id' => null]);
        $online = $this->invoice(['user_id' => User::factory()->create()->id, 'sales_channel' => 'online',
            'recipient' => 'Online recipient', 'subtotal' => 10000, 'shipping' => 0, 'quantity' => 1, 'paid_at' => '2026-10-14 10:00:00']);
        $partial = $this->invoice(['recipient' => 'Deposit recipient', 'payment_status' => 'partially_paid',
            'subtotal' => 190000, 'amount_paid' => 100000, 'quantity' => 999, 'paid_at' => '2026-10-15 11:00:00']);
        foreach ([[$cash, 'Cash tee'], [$online, 'Online tee'], [$partial, 'Deposit-only tee']] as [$invoice, $name]) {
            $this->items($invoice, $this->variant($name));
        }
        $filters = ['start_date' => '2026-10-14', 'end_date' => '2026-10-15'];
        $this->get(route('admin.dashboard', $filters))->assertOk()
            ->assertViewHas('revenue', 30000)->assertViewHas('ordersCount', 2)
            ->assertViewHas('best', fn ($rows) => $rows->pluck('name')->all() === ['Cash tee', 'Online tee'] && $rows->pluck('units')->map(fn ($n) => (int) $n)->all() === [2, 1])
            ->assertViewHas('salesChart', fn ($chart) => $chart['total'] === 30000 && $chart['orders'] === 2)
            ->assertSee('Guest invoice')->assertSee('Deposit recipient')->assertSee('₱150.00');
        $this->assertEqualsCanonicalizing([$cash->id, $online->id], app(SalesReport::class)->paidOrders()->pluck('id')->all());
        $export = $this->get(route('admin.reports.export', $filters))->assertOk()->streamedContent();
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), preg_split('/\r?\n/', trim($export)));
        $this->assertCount(3, $rows);
        $this->assertSame(['Recipient', 'Sales channel'], array_slice($rows[0], 9, 2));
        $this->assertSame($cash->number, $rows[1][0]);
        $this->assertSame("'=1+1", $rows[1][9]);
        $this->assertSame('Walk-in', $rows[1][10]);
        $this->assertSame('Online', $rows[2][10]);
        $this->assertStringNotContainsString($partial->number, $export);
        Http::assertNothingSent();
    }

    public function test_forecasts_use_completed_month_full_cash_sales_and_ignore_deposit_units(): void
    {
        $cashVariant = $this->variant('Fully settled history');
        $depositVariant = $this->variant('No settled history');
        foreach ([7, 8, 9] as $index => $month) {
            $date = sprintf('2026-%02d-15 10:00:00', $month);
            $cash = $this->invoice(['subtotal' => ($index + 1) * 10000, 'shipping' => 0,
                'quantity' => ($index + 1) * 2, 'paid_at' => $date, 'created_at' => $date, 'payment_id' => null]);
            $this->items($cash, $cashVariant);
            $deposit = $this->invoice(['payment_status' => 'partially_paid', 'subtotal' => 999900, 'shipping' => 0,
                'quantity' => 999, 'amount_paid' => 10000, 'paid_at' => $date, 'created_at' => $date]);
            $this->items($deposit, $cashVariant);
            $this->items($deposit, $depositVariant);
        }
        $forecast = app(Forecast::class);
        $revenue = $forecast->revenue();
        $this->assertSame([10000, 20000, 30000], $revenue['values']);
        $this->assertEquals(40000, $revenue['regression']['prediction']);
        $rows = collect($forecast->rows())->keyBy('name');
        $this->assertSame([2, 4, 6], $rows['Fully settled history']['values']);
        $this->assertSame(8, $rows['Fully settled history']['forecast']);
        $this->assertFalse($rows['No settled history']['observed_history']);
        $this->assertNull($rows['No settled history']['forecast']);
        Http::assertNothingSent();
    }

    public function test_operations_separates_current_deposits_and_balances_from_paid_stages_for_the_same_placed_date_cohort(): void
    {
        $this->staff();
        $this->invoice(['created_at' => '2026-10-11 10:00:00']);
        $this->invoice(['created_at' => '2026-10-10 10:00:00', 'sales_channel' => 'online',
            'user_id' => User::factory()->create()->id, 'delivery_method' => 'lalamove', 'status' => 'ready', 'quantity' => 3]);
        $overdue = $this->invoice(['created_at' => '2026-10-10 00:00:00', 'payment_status' => 'partially_paid',
            'subtotal' => 90000, 'amount_paid' => 30000, 'due_at' => '2026-10-15 11:59:59', 'delivery_method' => 'lalamove']);
        $later = $this->invoice(['created_at' => '2026-10-15 12:00:00', 'payment_status' => 'partially_paid',
            'subtotal' => 50000, 'amount_paid' => 10000, 'due_at' => '2026-10-16 12:00:00']);
        $pending = $this->invoice(['payment_status' => 'pending', 'subtotal' => 10000, 'due_at' => '2026-10-15 12:00:00']);
        $noDue = $this->invoice(['payment_status' => 'partially_paid', 'subtotal' => 10000, 'shipping' => 0, 'amount_paid' => 2000]);
        $this->invoice(['payment_status' => 'partially_paid', 'status' => 'cancelled', 'amount_paid' => 9000]);
        foreach (['2026-10-09 23:59:59', '2026-10-15 12:00:01', '2026-10-16 00:00:00'] as $excluded) {
            $this->invoice(['created_at' => $excluded, 'payment_status' => 'partially_paid', 'amount_paid' => 8000]);
        }
        $filters = ['start_date' => '2026-10-10', 'end_date' => '2026-10-15'];
        $this->get(route('admin.operations', $filters))->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['paid_orders'] === 2 && $stats['paid_pieces'] === 5
                && $stats['placed_orders'] === 7 && $stats['partially_paid'] === 3 && $stats['unpaid_pending'] === 1
                && $stats['outstanding_invoices'] === 4 && $stats['outstanding_amount'] === 148000
                && $stats['received_deposits'] === 42000 && $stats['overdue_invoices'] === 1)
            ->assertViewHas('deliveryTotals', ['total' => 1, 'completed' => 0, 'active' => 1, 'attention' => 0, 'ready' => 1])
            ->assertViewHas('statusRows', fn ($rows) => count($rows) === 4 && array_column($rows, 'count') === [0, 1, 0, 1])
            ->assertViewHas('chart', fn ($rows) => array_sum(array_column($rows, 'orders')) === 2)
            ->assertSee('Received deposits on these invoices')->assertSee('not receipts grouped by payment date')
            ->assertSee('₱1,480.00')->assertSee('₱420.00');
        $this->get(route('admin.orders', $filters + ['outstanding' => 1]))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 4
                && $orders->getCollection()->pluck('id')->sort()->values()->all() === collect([$overdue->id, $later->id, $pending->id, $noDue->id])->sort()->values()->all());
        $this->get(route('admin.orders', $filters + ['overdue' => 1]))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 1 && $orders->first()->id === $overdue->id);
        Http::assertNothingSent();
    }

    public function test_partial_walk_in_guest_filters_and_overdue_state_survive_pagination_without_a_fake_account(): void
    {
        $this->staff();
        for ($i = 0; $i < 21; $i++) {
            $this->invoice(['recipient' => 'Guest <script>alert(1)</script>', 'payment_status' => 'partially_paid',
                'amount_paid' => 5000, 'due_at' => '2026-10-15 11:59:59']);
        }
        $this->invoice(['payment_status' => 'partially_paid', 'sales_channel' => 'online', 'amount_paid' => 5000, 'due_at' => '2026-10-14 00:00:00']);
        $this->invoice(['payment_status' => 'partially_paid', 'status' => 'cancelled', 'amount_paid' => 5000, 'due_at' => '2026-10-14 00:00:00']);
        $this->invoice(['payment_status' => 'partially_paid', 'amount_paid' => 5000, 'due_at' => '2026-10-15 12:00:00']);
        $this->invoice(['payment_status' => 'partially_paid', 'amount_paid' => 5000]);
        $this->invoice();
        $filters = ['sales_channel' => 'walk_in', 'payment_status' => 'partially_paid', 'q' => 'Guest', 'overdue' => 1,
            'start_date' => '2026-10-14', 'end_date' => '2026-10-15'];
        $response = $this->get(route('admin.orders', $filters))->assertOk()->assertSee('Guest invoice')
            ->assertSee('Partially paid')->assertSee('Awaiting full payment')->assertSee('Overdue')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 21 && $orders->count() === 20
                && $orders->getCollection()->every(fn ($order) => $order->user_id === null));
        $next = $response->viewData('orders')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        foreach ($filters as $key => $value) {
            $this->assertSame((string) $value, $query[$key]);
        }
        $this->get($next)->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 21 && $orders->count() === 1);
        $this->assertSame(1, User::count());
        Http::assertNothingSent();
    }

    public function test_registered_walk_in_customer_history_includes_their_invoice_but_not_unlinked_guest_or_other_customer_invoices(): void
    {
        $buyer = User::factory()->create();
        $walkIn = $this->invoice(['user_id' => $buyer->id, 'payment_status' => 'partially_paid', 'amount_paid' => 5000,
            'due_at' => '2026-10-14 10:00:00']);
        $online = $this->invoice(['user_id' => $buyer->id, 'sales_channel' => 'online']);
        $guest = $this->invoice();
        $other = $this->invoice(['user_id' => User::factory()->create()->id]);
        $this->actingAs($buyer)->get(route('orders'))->assertOk()->assertSee($walkIn->number)->assertSee($online->number)
            ->assertDontSee($guest->number)->assertDontSee($other->number)->assertSee('Walk-in')->assertSee('Partially paid')
            ->assertSee('Received: ₱50.00')->assertSee('Outstanding: ₱250.00')->assertSee('Overdue')
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 2);
        Http::assertNothingSent();
    }

    public function test_operations_labels_recorded_cash_refunds_separately_and_requires_matching_cash_receipt_and_owner_audit(): void
    {
        $this->staff();
        $owner = User::factory()->create(['role' => 'admin']);
        $cashOrder = $this->invoice();
        $otherOrder = $this->invoice();
        $receipt = PaymentAttempt::create(['order_id' => $cashOrder->id, 'reference' => (string) Str::uuid(),
            'method' => 'cash', 'status' => 'paid', 'amount' => 30000, 'received_at' => now(),
            'recorded_by' => $owner->id, 'reason' => 'Owner received cash for this invoice.']);
        $makeRefund = function (array $attributes = [], ?User $auditActor = null) use ($owner, $cashOrder, $receipt) {
            $refund = Refund::create($attributes + ['order_id' => $cashOrder->id, 'requested_by' => null,
                'requested_amount' => 5000, 'approved_amount' => 5000, 'amount' => 5000,
                'status' => 'succeeded', 'reason' => 'Recorded cash return to the customer.', 'confirmed_at' => now(),
                'payment_attempt_id' => $receipt->id, 'cash_recorded_by' => $owner->id,
                'cash_reason' => 'The owner returned the approved cash amount.']);
            if ($auditActor) {
                app(ActivityRecorder::class)->record($auditActor, 'refund.cash_recorded', $refund,
                    ['reason' => $refund->cash_reason, 'amount' => $refund->amount]);
            }

            return $refund;
        };
        $valid = $makeRefund(auditActor: $owner);
        $makeRefund();
        $foreignReceipt = $receipt->replicate()->fill(['order_id' => $otherOrder->id, 'reference' => (string) Str::uuid()]);
        $foreignReceipt->save();
        $makeRefund(['payment_attempt_id' => $foreignReceipt->id], $owner);
        foreach ([['status' => 'pending'], ['received_at' => null], ['method' => 'paymongo']] as $invalid) {
            $invalidReceipt = $receipt->replicate()->fill($invalid + ['reference' => (string) Str::uuid()]);
            $invalidReceipt->save();
            $makeRefund(['payment_attempt_id' => $invalidReceipt->id], $owner);
        }
        $makeRefund(['cash_reason' => null], $owner);
        $makeRefund(['amount' => 40000], $owner);
        $makeRefund(auditActor: User::factory()->create(['role' => 'staff']));
        Refund::create(['order_id' => $cashOrder->id, 'requested_by' => null, 'requested_amount' => 3000,
            'approved_amount' => 3000, 'amount' => 3000, 'status' => 'succeeded', 'reason' => 'Previously verified PayMongo refund.',
            'provider_refund_id' => 'ref_reporting_verified', 'confirmed_at' => now()]);
        $this->get(route('admin.operations', ['start_date' => '2026-10-14', 'end_date' => '2026-10-15']))->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['confirmed_refunds'] === 2 && $stats['refund_amount'] === 8000
                && $stats['provider_refunds'] === 1 && $stats['provider_refund_amount'] === 3000
                && $stats['cash_refunds'] === 1 && $stats['cash_refund_amount'] === 5000)
            ->assertViewHas('chart', fn ($rows) => array_sum(array_column($rows, 'refunds')) === 2
                && array_sum(array_column($rows, 'refund_amount')) === 8000)
            ->assertSee('PayMongo-confirmed refunds')->assertSee('Recorded cash refunds')
            ->assertSee('not a PayMongo confirmation')->assertSee('₱30.00')->assertSee('₱50.00');
        $this->assertNull($valid->provider_refund_id);
        $this->assertSame(30000, $cashOrder->fresh()->total);
        $this->assertSame(30000, $cashOrder->fresh()->amount_paid);
        $this->assertSame('paid', $cashOrder->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_partial_payment_filters_do_not_create_a_delivery_stage_and_reject_invalid_channel_or_balance_values(): void
    {
        $this->staff();
        foreach ([['sales_channel' => 'retail'], ['overdue' => 'yesterday'], ['outstanding' => ['1']],
            ['payment_status' => 'deposit'], ['fulfillment' => 'partially_paid']] as $invalid) {
            $this->getJson(route('admin.orders', $invalid))->assertUnprocessable()->assertJsonValidationErrors(array_key_first($invalid));
        }
        $this->invoice(['payment_status' => 'partially_paid', 'status' => 'ready', 'amount_paid' => 5000]);
        foreach (['packing', 'ready', 'courier', 'delivered'] as $stage) {
            $this->get(route('admin.orders', ['fulfillment' => $stage]))->assertOk()
                ->assertViewHas('orders', fn ($orders) => $orders->total() === 0);
        }
        Http::assertNothingSent();
    }
}
