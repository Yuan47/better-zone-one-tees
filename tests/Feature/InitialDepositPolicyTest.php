<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\InvoicePayments;
use App\Services\InvoiceTerms;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InitialDepositPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_deposit_fixture',
            'shop.paymongo.webhook_secret' => 'deposit-fixture-webhook']);
        Http::preventStrayRequests();
    }

    private function variant(int $retail = 120001): Variant
    {
        $product = Product::create(['name' => 'Deposit policy tee', 'slug' => 'deposit-'.Str::uuid(), 'category' => '', 'description' => 'Isolated fixture']);

        return $product->variants()->create(['sku' => 'DEPOSIT-'.Str::uuid(), 'size' => 'M', 'color' => 'White', 'stock' => 10,
            'retail_price' => $retail, 'wholesale_price' => $retail - 5000, 'reseller_price' => $retail - 10000]);
    }

    private function invoice(bool $guest = false, int $total = 120001): Order
    {
        $buyer = $guest ? null : User::factory()->create(['role' => 'customer']);
        $variant = $this->variant($total);

        return app(OrderFlow::class)->reserve([$variant->id => 1], ['recipient' => 'Deposit fixture buyer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0, 'sales_channel' => $guest ? 'walk_in' : 'online',
            'payment_plan' => 'partial', 'due_at' => now()->addDays(14)], $buyer?->id)->fresh();
    }

    private function provider(): void
    {
        $sequence = 0;
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => function () use (&$sequence) {
            $id = 'cs_deposit_fixture_'.++$sequence;

            return Http::response(['data' => ['id' => $id, 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/'.$id]]]);
        }]);
    }

    private function cash(int $amount): array
    {
        return ['amount' => number_format($amount / 100, 2, '.', ''), 'reason' => 'Cash counted and received for isolated fixture.',
            'payment_received' => '1', 'request_key' => (string) Str::uuid()];
    }

    public static function firstCashAmounts(): array
    {
        return [['600.00', false], ['600.01', true], ['600.02', true], ['1199.01', true],
            ['1199.02', false], ['1200.00', false], ['1200.01', true]];
    }

    #[DataProvider('firstCashAmounts')]
    public function test_first_cash_payment_requires_rounded_up_half_and_cannot_leave_a_sub_peso_balance(string $amount, bool $valid): void
    {
        $order = $this->invoice();
        $before = $order->getAttributes();
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $response = $this->postJson('/admin/orders/'.$order->id.'/payments/cash', array_replace($this->cash(60001), ['amount' => $amount]));
        if ($valid) {
            $response->assertRedirect();
            $this->assertSame(InvoiceTerms::amount($amount), $order->fresh()->amount_paid);
            $this->assertDatabaseCount('payment_attempts', 1);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('amount');
            $this->assertSame($before, $order->fresh()->getAttributes());
            $this->assertDatabaseCount('payment_attempts', 0);
            $this->assertDatabaseCount('activity_logs', 0);
            $this->assertSame(10, Variant::sole()->stock);
            $this->assertSame(1, Variant::sole()->reserved);
        }
        Http::assertNothingSent();
    }

    public static function customerDeliveryDeposits(): array
    {
        return [['700.00', false], ['700.01', true]];
    }

    #[DataProvider('customerDeliveryDeposits')]
    public function test_customer_initial_deposit_includes_delivery_and_rejected_creation_rolls_back_stock(string $amount, bool $valid): void
    {
        $variant = $this->variant();
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $this->provider();
        $address = ['street_address' => '12 Example Street', 'city' => 'Imus', 'province' => 'Cavite', 'postal_code' => '4103',
            'country' => 'Philippines', 'lat' => '14.425', 'lng' => '120.94'];
        $quote = ['quotationId' => 'quote_deposit_fixture', 'priceBreakdown' => ['currency' => 'PHP', 'total' => '200.00'],
            'expiresAt' => now()->addMinutes(5)->toIso8601String(), 'stops' => [['stopId' => 'pickup'], ['stopId' => 'recipient']]];
        $response = $this->withSession(['cart' => [$variant->id => 1], 'delivery_quote' => ['quote' => $quote,
            'address' => '12 Example Street, Imus, Cavite, 4103, Philippines', 'lat' => $address['lat'], 'lng' => $address['lng']]])
            ->post('/checkout', $address + ['recipient' => 'Deposit fixture buyer', 'phone' => '09123456789', 'delivery_method' => 'lalamove',
                'payment_option' => 'partial', 'payment_amount' => $amount, 'payment_days' => 14]);
        if ($valid) {
            $response->assertSessionHasNoErrors()->assertRedirect('https://checkout.paymongo.com/cs_deposit_fixture_1');
            $this->assertSame(140001, Order::sole()->total);
            $this->assertSame(20000, Order::sole()->shipping);
            $this->assertSame(70001, PaymentAttempt::sole()->amount);
            $this->assertSame(0, Order::sole()->amount_paid);
            Http::assertSentCount(1);
        } else {
            $response->assertSessionHasErrors('payment_amount');
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_items', 0);
            $this->assertDatabaseCount('payment_attempts', 0);
            $this->assertSame(0, $variant->fresh()->reserved);
            Http::assertNothingSent();
        }
    }

    public static function walkInPaths(): array
    {
        return [['guest', 'partial_cash'], ['registered', 'partial_cash'], ['guest', 'partial_paymongo'], ['registered', 'partial_paymongo']];
    }

    #[DataProvider('walkInPaths')]
    public function test_new_walk_in_initial_partial_payment_is_atomic_for_guests_and_registered_customers(string $customerType, string $option): void
    {
        $variant = $this->variant();
        $customer = $customerType === 'registered' ? User::factory()->create(['role' => 'customer']) : null;
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $this->provider();
        $payload = ['request_key' => (string) Str::uuid(), 'customer_type' => $customerType, 'customer_id' => $customer?->id,
            'recipient' => 'Deposit fixture guest', 'phone' => '09123456789', 'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
            'payment_option' => $option, 'amount' => '600.00', 'due_at' => now()->addDays(14)->toDateString(),
            'payment_received' => '1', 'reason' => 'Cash counted and received for isolated fixture.'];
        $this->post('/admin/walk-in', $payload)->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();

        $payload['amount'] = '600.01';
        $this->post('/admin/walk-in', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(120001, Order::sole()->total);
        $this->assertSame(60001, PaymentAttempt::sole()->amount);
        $this->assertSame($customer?->id, Order::sole()->user_id);
        $this->assertFalse(Order::sole()->inventory_committed);
        $this->assertSame(1, $variant->fresh()->reserved);
        if ($option === 'partial_cash') {
            $this->assertSame(60001, Order::sole()->amount_paid);
            $this->assertSame('partially_paid', Order::sole()->payment_status);
            Http::assertNothingSent();
        } else {
            $this->assertSame(0, Order::sole()->amount_paid);
            $this->assertSame('pending', PaymentAttempt::sole()->status);
            Http::assertSentCount(1);
        }
    }

    public function test_guest_signed_payment_cannot_bypass_the_first_deposit_minimum(): void
    {
        $order = $this->invoice(true);
        $this->provider();
        $url = URL::temporarySignedRoute('guest.orders.pay', now()->addHour(), ['order' => $order->id]);
        $this->postJson($url, ['amount' => '600.00', 'request_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_subsequent_peso_receipts_and_exact_final_balance_preserve_deadlines_and_idempotency(): void
    {
        $order = $this->invoice();
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff);
        $original = array_intersect_key($order->getAttributes(), array_flip(['number', 'total', 'subtotal', 'due_at', 'original_due_at']));
        $this->post('/admin/orders/'.$order->id.'/payments/cash', $this->cash(60001))->assertSessionHasNoErrors();
        $this->post('/admin/orders/'.$order->id.'/payments/cash', $this->cash(100))->assertSessionHasNoErrors();
        $final = $this->cash(59900);
        $this->post('/admin/orders/'.$order->id.'/payments/cash', $final)->assertSessionHasNoErrors();
        $this->post('/admin/orders/'.$order->id.'/payments/cash', $final)->assertSessionHasNoErrors();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(120001, $order->fresh()->amount_paid);
        $this->assertSame(0, $order->fresh()->outstandingBalance());
        $this->assertSame($original, array_intersect_key($order->fresh()->getAttributes(), $original));
        $this->assertDatabaseCount('payment_attempts', 3);
        $this->assertSame(9, Variant::sole()->stock);
        $this->assertSame(0, Variant::sole()->reserved);
        Http::assertNothingSent();
    }

    public function test_existing_small_pending_provider_attempt_is_replayed_and_accepted_without_retroactive_deposit_rejection(): void
    {
        $order = $this->invoice();
        $key = (string) Str::uuid();
        $attempt = $order->payments()->create(['reference' => $key, 'method' => 'paymongo', 'status' => 'pending', 'amount' => 10050,
            'checkout_session_id' => 'cs_legacy_deposit_fixture', 'checkout_url' => 'https://checkout.paymongo.com/cs_legacy_deposit_fixture']);
        $this->assertSame($attempt->id, app(InvoicePayments::class)->start($order, 10050, $order->user, $key)->id);
        $deadline = $order->getRawOriginal('due_at');
        $this->assertSame('partially_paid', app(InvoicePayments::class)->receiveProvider($attempt, ['id' => 'pay_legacy_deposit_fixture',
            'attributes' => ['status' => 'paid', 'amount' => 10050, 'currency' => 'PHP', 'livemode' => false, 'paid_at' => now()->timestamp]]));
        $this->assertSame(10050, $order->fresh()->amount_paid);
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $this->post('/admin/orders/'.$order->id.'/payments/cash', $this->cash(100))->assertSessionHasNoErrors();
        $this->assertSame(10150, $order->fresh()->amount_paid);
        $this->assertSame($deadline, $order->fresh()->getRawOriginal('due_at'));
        $this->assertDatabaseCount('payment_attempts', 2);
        Http::assertNothingSent();
    }

    public function test_new_online_and_cash_payments_cannot_leave_tiny_balances_after_a_received_deposit(): void
    {
        $order = $this->invoice();
        $staff = User::factory()->create(['role' => 'staff']);
        app(InvoicePayments::class)->recordCash($order, 60001, $staff, 'Cash counted and received for isolated fixture.', (string) Str::uuid());
        $before = $order->fresh()->getAttributes();
        $this->actingAs($staff)->postJson('/admin/orders/'.$order->id.'/payments/cash', $this->cash(59999))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/payments', ['amount' => '599.99', 'request_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_attempts', 1);
        Http::assertNothingSent();
    }

    public function test_an_existing_provider_payment_that_leaves_a_tiny_balance_is_preserved_and_flagged_for_owner_reconciliation(): void
    {
        $order = $this->invoice();
        $attempt = $order->payments()->create(['reference' => (string) Str::uuid(), 'method' => 'paymongo', 'status' => 'pending', 'amount' => 120000]);
        app(InvoicePayments::class)->receiveProvider($attempt, ['id' => 'pay_legacy_tiny_balance',
            'attributes' => ['status' => 'paid', 'amount' => 120000, 'currency' => 'PHP', 'livemode' => false, 'paid_at' => now()->timestamp]]);
        $this->assertSame(120000, $order->fresh()->amount_paid);
        $this->assertSame(1, $order->fresh()->outstandingBalance());
        $this->actingAs($order->user)->get('/orders/'.$order->id)->assertOk()->assertSee('below ₱1.00 and needs owner reconciliation')
            ->assertDontSee('Record cash received')->assertDontSee('Pay this invoice online');
        $this->assertSame('partially_paid', $order->fresh()->payment_status);
        $this->assertSame(120001, $order->fresh()->total);
        Http::assertNothingSent();
    }

    public function test_exact_half_of_an_even_invoice_is_accepted_without_rounding_up_an_extra_centavo(): void
    {
        $order = $this->invoice(false, 120000);
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $this->post('/admin/orders/'.$order->id.'/payments/cash', $this->cash(60000))->assertSessionHasNoErrors();
        $this->assertSame(60000, $order->fresh()->amount_paid);
        $this->assertSame(60000, $order->fresh()->outstandingBalance());
        $this->assertSame('partially_paid', $order->fresh()->payment_status);
        Http::assertNothingSent();
    }
}
