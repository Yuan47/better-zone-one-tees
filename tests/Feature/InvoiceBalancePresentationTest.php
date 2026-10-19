<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceBalancePresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'Asia/Manila'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function invoice(User $customer, array $attributes = []): Order
    {
        return Order::create($attributes + [
            'number' => 'BALANCE-'.Str::random(10), 'user_id' => $customer->id,
            'recipient' => 'Balance buyer', 'phone' => '09123456789', 'address' => 'Store pickup',
            'delivery_method' => 'pickup', 'sales_channel' => 'online', 'tier' => 'retail',
            'quantity' => 6, 'merchandise_base' => 94200, 'discount' => 0, 'subtotal' => 94200,
            'shipping' => 0, 'total' => 94200, 'payment_status' => 'pending', 'amount_paid' => 0,
            'status' => 'awaiting_payment', 'inventory_released' => false,
            'due_at' => '2026-10-26 12:00:00', 'original_due_at' => '2026-10-26 12:00:00',
        ])->fresh();
    }

    public static function closedInvoices(): array
    {
        return [
            'cancelled and released' => ['cancelled', true],
            'cancelled legacy invoice' => ['cancelled', false],
            'released invoice' => ['awaiting_payment', true],
        ];
    }

    #[DataProvider('closedInvoices')]
    public function test_closed_invoices_in_staff_list_have_no_current_payment_obligation(string $status, bool $released): void
    {
        $order = $this->invoice(User::factory()->create(['role' => 'customer']), ['status' => $status, 'inventory_released' => $released]);
        $before = $order->getAttributes();
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->get(route('admin.orders', ['q' => $order->number]))->assertOk()
            ->assertSee('No payment due')->assertSee('Closed invoice')->assertSee('Original invoice')
            ->assertSee('₱942.00')->assertDontSee('Due Oct 26, 2026')->assertDontSee('Awaiting full payment');
        $this->assertSame($before, $order->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    #[DataProvider('closedInvoices')]
    public function test_closed_invoices_in_customer_history_retain_original_value_without_due_prompts(string $status, bool $released): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->invoice($customer, ['status' => $status, 'inventory_released' => $released]);
        $before = $order->getAttributes();
        $this->actingAs($customer)->get(route('orders'))->assertOk()
            ->assertSee('No payment due')->assertSee('Closed invoice')->assertSee('Original invoice')
            ->assertSee('₱942.00')->assertDontSee('Outstanding: ₱942.00')->assertDontSee('Due Oct 26, 2026');
        $this->assertSame($before, $order->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    #[DataProvider('closedInvoices')]
    public function test_closed_invoice_payment_summary_keeps_history_without_active_payment_or_packing_prompts(string $status, bool $released): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->invoice($customer, ['status' => $status, 'inventory_released' => $released,
            'payment_status' => 'partially_paid', 'amount_paid' => 20000]);
        $before = $order->getAttributes();
        $this->actingAs($customer)->get(route('orders.show', $order))->assertOk()
            ->assertSee('No payment due')->assertSee('Closed invoice')->assertSee('Original invoice')
            ->assertSee('₱942.00')->assertSee('₱200.00')->assertSee('₱742.00')->assertSee('Uncollected at closure')->assertSee('Recorded balance deadline')
            ->assertDontSee('Balance due')->assertDontSee('Another payment does not extend')
            ->assertDontSee('Packing and delivery begin')->assertDontSee('Pay this invoice online');
        $this->assertSame($before, $order->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    #[DataProvider('closedInvoices')]
    public function test_closed_invoice_keeps_payment_history_without_staff_collection_controls(string $status, bool $released): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->invoice($customer, ['status' => $status, 'inventory_released' => $released,
            'payment_status' => 'partially_paid', 'amount_paid' => 20000,
            'due_at' => '2026-10-18 12:00:00', 'original_due_at' => '2026-10-18 12:00:00']);
        $order->payments()->create(['method' => 'cash', 'status' => 'paid', 'amount' => 20000,
            'reference' => 'retained-cash-receipt', 'request_key' => (string) Str::uuid(), 'received_at' => now()->subDays(2)]);
        $order->payments()->create(['method' => 'paymongo', 'status' => 'pending', 'amount' => 74200,
            'reference' => 'retained-pending-checkout', 'request_key' => (string) Str::uuid(),
            'checkout_session_id' => 'cs_closed_invoice', 'checkout_url' => 'https://checkout.paymongo.com/closed-invoice']);
        $before = $order->getAttributes();
        $paymentsBefore = PaymentAttempt::orderBy('id')->get()->map->getAttributes()->all();

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('orders.show', $order))->assertOk()
            ->assertSee('Closed invoice')->assertSee('No payment due')->assertSee('Recorded balance deadline')
            ->assertSee('retained-cash-receipt')->assertSee('retained-pending-checkout')->assertSee('₱742.00')
            ->assertDontSee('Balance due')->assertDontSee('Overdue')->assertDontSee('Another payment does not extend')
            ->assertDontSee('Packing and delivery begin')->assertDontSee('Continue saved PayMongo payment')
            ->assertDontSee('Check payment with PayMongo')->assertDontSee('Close this online checkout')
            ->assertDontSee('Pay this invoice online')->assertDontSee('Record cash received')
            ->assertDontSee('Record an agreed deadline change');

        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($paymentsBefore, PaymentAttempt::orderBy('id')->get()->map->getAttributes()->all());
        Http::assertNothingSent();
    }

    #[DataProvider('closedInvoices')]
    public function test_closed_guest_invoice_has_no_payment_actions(string $status, bool $released): void
    {
        $order = $this->invoice(User::factory()->create(['role' => 'customer']), [
            'user_id' => null, 'sales_channel' => 'walk_in', 'status' => $status, 'inventory_released' => $released,
            'payment_status' => 'partially_paid', 'amount_paid' => 20000,
        ]);
        $before = $order->getAttributes();
        $this->get(URL::temporarySignedRoute('guest.orders.show', now()->addHour(), ['order' => $order->id]))->assertOk()
            ->assertSee('Closed invoice')->assertSee('No payment due')->assertSee('Recorded balance deadline')
            ->assertSee('₱942.00')->assertSee('₱200.00')->assertSee('₱742.00')
            ->assertDontSee('Balance due')->assertDontSee('Walk-in rule: payment must be received in full')
            ->assertDontSee('Pay this invoice online')->assertDontSee('Check payment with PayMongo');
        $this->assertGuest();
        $this->assertSame($before, $order->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public static function settledInvoiceAudiences(): array
    {
        return ['customer' => [false], 'guest' => [true]];
    }

    #[DataProvider('settledInvoiceAudiences')]
    public function test_settled_customer_and_guest_invoices_label_deadlines_as_history(bool $guest): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $attributes = ['payment_status' => 'paid', 'amount_paid' => 94200, 'status' => 'packing', 'paid_at' => now()];
        $order = $this->invoice($customer, $attributes + ($guest ? ['user_id' => null, 'sales_channel' => 'walk_in'] : []));
        $url = $guest ? URL::temporarySignedRoute('guest.orders.show', now()->addHour(), ['order' => $order->id]) : route('orders.show', $order);
        if (! $guest) {
            $this->actingAs($customer);
        }
        $this->get($url)->assertOk()->assertSee('Recorded balance deadline')->assertSee('Oct 26, 2026')
            ->assertSee('₱942.00')->assertSee('₱0.00')->assertDontSee('Balance due')
            ->assertDontSee('Another payment does not extend')->assertDontSee('Pay this invoice online');
        $this->assertSame(94200, $order->fresh()->amount_paid);
        $this->assertSame('2026-10-26 12:00:00', $order->fresh()->getRawOriginal('due_at'));
        Http::assertNothingSent();
    }

    public function test_open_invoice_still_shows_its_real_balance_deadline_and_overdue_warning(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->invoice($customer, ['payment_status' => 'partially_paid', 'amount_paid' => 20000,
            'due_at' => '2026-10-18 12:00:00', 'original_due_at' => '2026-10-18 12:00:00']);
        $this->actingAs($customer)->get(route('orders'))->assertOk()
            ->assertSee('Outstanding: ₱742.00')->assertSee('Due Oct 18, 2026')->assertSee('Overdue');
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Remaining balance')->assertSee('₱742.00')
            ->assertSee('Balance due')->assertSee('Overdue')->assertSee('Another payment does not extend this deadline')
            ->assertSee('Packing and delivery begin')->assertSee('Pay this invoice online')->assertDontSee('No payment due');
        Http::assertNothingSent();
    }
}
