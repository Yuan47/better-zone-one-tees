<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\InvoicePayments;
use App\Services\OrderFlow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerInstallmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 12:34:56', 'Asia/Manila'));
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_customer_installments',
            'shop.paymongo.webhook_secret' => 'customer-installment-webhook-test']);
        DB::table('shop_settings')->updateOrInsert(['key' => 'partial_payment_due_days'], ['value' => '7']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function variant(int $retail = 60000, int $reseller = 50000): Variant
    {
        $product = Product::create(['name' => 'Customer Installment Tee', 'slug' => 'customer-installment-'.uniqid(), 'category' => '', 'description' => 'Customer installment fixture']);

        return $product->variants()->create(['sku' => 'CUSTOMER-INSTALLMENT-'.uniqid(), 'size' => 'M', 'color' => 'White', 'stock' => 500,
            'retail_price' => $retail, 'wholesale_price' => 55000, 'reseller_price' => $reseller]);
    }

    private function buyer(Variant $variant, int $quantity = 2): User
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->withSession(['cart' => [$variant->id => $quantity], 'pending_order' => null]);

        return $customer;
    }

    private function details(array $extra = []): array
    {
        return $extra + ['recipient' => 'Installment Customer', 'phone' => '09123456789', 'delivery_method' => 'pickup',
            'payment_option' => 'partial', 'payment_amount' => '250.25', 'payment_days' => 14];
    }

    private function provider(): void
    {
        $sequence = 0;
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => function () use (&$sequence) {
            $id = 'cs_customer_installment_'.++$sequence;

            return Http::response(['data' => ['id' => $id, 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/'.$id]]]);
        }]);
    }

    private function checkoutKey(): string
    {
        $response = $this->get('/checkout')->assertOk();
        $key = $response->viewData('checkoutKey');
        $this->assertTrue(Str::isUuid($key));
        $response->assertSee('name="checkout_key" value="'.$key.'"', false);

        return $key;
    }

    private function assertNoInvoiceOrReservation(Variant $variant): void
    {
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(500, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    public function test_partial_checkout_payload_and_ledger_match_the_selected_amount_without_changing_the_invoice_or_confirming_payment(): void
    {
        $variant = $this->variant();
        $customer = $this->buyer($variant);
        $this->provider();
        $this->post('/checkout', $this->details())->assertRedirect('https://checkout.paymongo.com/cs_customer_installment_1')->assertSessionHasNoErrors();
        $order = Order::sole();
        $attempt = PaymentAttempt::sole();
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame('online', $order->sales_channel);
        $this->assertSame('partial', $order->payment_plan);
        $this->assertSame(120000, $order->total);
        $this->assertSame(120000, $order->subtotal);
        $this->assertSame(60000, $order->items()->sole()->unit_price);
        $this->assertSame(25025, $attempt->amount);
        $this->assertSame('pending', $attempt->status);
        $this->assertSame(0, $order->paidAmount());
        $this->assertSame(120000, $order->outstandingBalance());
        $this->assertNull($order->paid_at);
        $this->assertFalse($order->inventory_committed);
        $this->assertSame(2, $variant->fresh()->reserved);
        $this->assertSame(500, $variant->fresh()->stock);
        $this->assertTrue($order->due_at->equalTo(now()->addDays(14)));
        $this->assertSame($order->getRawOriginal('due_at'), $order->getRawOriginal('original_due_at'));
        $this->get('/orders')->assertOk()->assertSee($order->number)->assertSee('Outstanding');
        $this->get('/orders/'.$order->id)->assertOk()->assertSee($order->number)->assertSee('250.25');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => array_sum(array_map(fn ($line) => $line['amount'] * $line['quantity'], $request['data']['attributes']['line_items'])) === 25025
            && $request['data']['attributes']['reference_number'] === $order->number
            && $request['data']['attributes']['metadata']['invoice_payment_reference'] === $attempt->reference);
    }

    public function test_full_checkout_ignores_client_payment_amount_invoice_totals_and_channel_flags(): void
    {
        $variant = $this->variant();
        $customer = $this->buyer($variant);
        $this->provider();
        $this->post('/checkout', $this->details(['payment_option' => 'full', 'payment_amount' => '0.01', 'total' => 1, 'subtotal' => 1,
            'shipping' => 999999, 'discount' => 999999, 'quantity' => 1, 'unit_price' => 1, 'sales_channel' => 'walk_in',
            'user_id' => 999999, 'payment_status' => 'paid', 'customer_confirmed_at' => now()->toDateTimeString(), 'walk_in_received_at' => now()->toDateTimeString()]))
            ->assertRedirect('https://checkout.paymongo.com/cs_customer_installment_1')->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame('full', $order->payment_plan);
        $this->assertSame('online', $order->sales_channel);
        $this->assertSame(120000, $order->total);
        $this->assertSame(0, $order->shipping);
        $this->assertSame(0, $order->discount);
        $this->assertSame(2, $order->quantity);
        $this->assertSame(120000, PaymentAttempt::sole()->amount);
        $this->assertNull($order->due_at);
        $this->assertNull($order->original_due_at);
        $this->assertNull($order->customer_confirmed_at);
        $this->assertNull($order->walk_in_received_at);
        $this->assertSame('pending', $order->payment_status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => array_sum(array_map(fn ($line) => $line['amount'] * $line['quantity'], $request['data']['attributes']['line_items'])) === 120000);
    }

    public function test_rebuilding_the_same_cart_allows_a_new_full_payment_checkout_while_the_first_installment_remains_open(): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        $this->provider();
        $firstKey = $this->checkoutKey();
        $this->post('/checkout', $this->details(['checkout_key' => $firstKey]))->assertSessionHasNoErrors();
        $first = Order::sole();
        $before = $first->getAttributes();
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $secondKey = $this->checkoutKey();
        $this->assertNotSame($firstKey, $secondKey);
        $this->post('/checkout', $this->details(['checkout_key' => $secondKey, 'payment_option' => 'full']))
            ->assertSessionHasNoErrors()->assertRedirect('https://checkout.paymongo.com/cs_customer_installment_2');
        $second = Order::whereKeyNot($first->id)->sole();
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertSame('partial', $first->fresh()->payment_plan);
        $this->assertSame('awaiting_payment', $first->fresh()->status);
        $this->assertSame('full', $second->payment_plan);
        $this->assertSame(120000, $second->payments()->sole()->amount);
        $this->assertSame($firstKey, $first->creation_key);
        $this->assertSame($secondKey, $second->creation_key);
        $this->assertSame(4, $variant->fresh()->reserved);
        $this->assertDatabaseCount('orders', 2);
        Http::assertSentCount(2);
    }

    public function test_legacy_client_can_start_a_new_full_purchase_after_rebuilding_a_consumed_cart(): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        $this->provider();
        $this->post('/checkout', $this->details())->assertSessionHasNoErrors();
        $first = Order::sole();
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post('/checkout', $this->details(['payment_option' => 'full']))->assertSessionHasNoErrors()
            ->assertRedirect('https://checkout.paymongo.com/cs_customer_installment_2');
        $second = Order::whereKeyNot($first->id)->sole();
        $this->assertNotSame($first->creation_key, $second->creation_key);
        $this->assertSame('full', $second->payment_plan);
        $this->assertSame(4, $variant->fresh()->reserved);
        Http::assertSentCount(2);
    }

    public function test_replaying_the_same_form_before_and_after_payment_does_not_duplicate_the_order_or_consume_a_new_cart(): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        $this->provider();
        $key = $this->checkoutKey();
        $details = $this->details(['checkout_key' => $key, 'payment_option' => 'full']);
        $this->post('/checkout', $details)->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post('/checkout', $details)->assertRedirect(route('orders.show', $order))->assertSessionHas('cart', [$variant->id => 2]);
        app(InvoicePayments::class)->receiveProvider($order->payments()->sole(), ['id' => 'pay_checkout_replay_test', 'attributes' => [
            'status' => 'paid', 'currency' => 'PHP', 'amount' => 120000, 'livemode' => false, 'paid_at' => now()->timestamp,
        ]]);
        $paid = $order->fresh()->getAttributes();
        $this->post('/checkout', $details)->assertRedirect(route('orders.show', $order))->assertSessionHas('cart', [$variant->id => 2]);
        $this->assertSame($paid, $order->fresh()->getAttributes());
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(498, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->reserved);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        Http::assertSentCount(1);
    }

    public static function retryClients(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('retryClients')]
    public function test_timeout_retries_keep_one_uncertain_order_and_one_stock_reservation(bool $formKey): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::failedConnection()]);
        $details = $this->details(['payment_option' => 'full'] + ($formKey ? ['checkout_key' => $this->checkoutKey()] : []));
        $this->post('/checkout', $details)->assertSessionHasErrors('payment');
        $order = Order::sole();
        $this->assertSame('unknown', $order->payments()->sole()->status);
        $this->patch('/cart/'.$variant->id, ['quantity' => 2])->assertSessionHasNoErrors();
        $this->assertSame($order->creation_key, $this->checkoutKey());
        $this->post('/checkout', $details)->assertRedirect(route('orders.show', $order))->assertSessionHas('cart', [$variant->id => 2]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(500, $variant->fresh()->stock);
        $this->assertSame(2, $variant->fresh()->reserved);
        Http::assertSentCount(1);
    }

    public function test_reopening_unchanged_checkout_in_two_tabs_keeps_one_request_and_one_invoice(): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        $this->provider();
        $firstKey = $this->checkoutKey();
        $secondKey = $this->checkoutKey();
        $this->assertSame($firstKey, $secondKey);
        $this->post('/checkout', $this->details(['checkout_key' => $firstKey, 'payment_option' => 'full']))->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->post('/checkout', $this->details(['checkout_key' => $secondKey, 'payment_option' => 'full']))->assertRedirect(route('orders.show', $order));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(2, $variant->fresh()->reserved);
        Http::assertSentCount(1);
    }

    public function test_a_new_checkout_can_replace_a_confirmed_closed_invoice_without_reusing_its_old_form(): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::sequence()->pushFailedConnection()->push([
            'data' => ['id' => 'cs_closed_invoice_replacement', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/replacement']],
        ])]);
        $firstKey = $this->checkoutKey();
        $details = $this->details(['checkout_key' => $firstKey, 'payment_option' => 'full']);
        $this->post('/checkout', $details)->assertSessionHasErrors('payment');
        $first = Order::sole();
        $first->payments()->update(['status' => 'failed']);
        app(OrderFlow::class)->cancel($first);
        $this->assertSame(0, $variant->fresh()->reserved);
        $secondKey = $this->checkoutKey();
        $this->assertNotSame($firstKey, $secondKey);
        $this->post('/checkout', $details)->assertRedirect(route('orders.show', $first));
        $this->assertDatabaseCount('orders', 1);
        $this->post('/checkout', array_replace($details, ['checkout_key' => $secondKey]))->assertSessionHasNoErrors();
        $second = Order::whereKeyNot($first->id)->sole();
        $this->assertSame($secondKey, $second->creation_key);
        $this->assertSame(2, $variant->fresh()->reserved);
        $this->assertTrue($first->fresh()->inventory_released);
        Http::assertSentCount(2);
    }

    public function test_a_stale_form_remains_bound_to_its_original_invoice_after_a_second_checkout(): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        $this->provider();
        $firstKey = $this->checkoutKey();
        $firstDetails = $this->details(['checkout_key' => $firstKey, 'payment_option' => 'full']);
        $this->post('/checkout', $firstDetails)->assertSessionHasNoErrors();
        $first = Order::sole();
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 3])->assertSessionHasNoErrors();
        $secondKey = $this->checkoutKey();
        $this->post('/checkout', $this->details(['checkout_key' => $secondKey, 'payment_option' => 'full']))->assertSessionHasNoErrors();
        $second = Order::whereKeyNot($first->id)->sole();
        $this->post('/checkout', $firstDetails)->assertRedirect(route('orders.show', $first));
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(5, $variant->fresh()->reserved);
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('payment_attempts', 2);
        Http::assertSentCount(2);
    }

    public function test_foreign_and_unissued_checkout_keys_cannot_create_an_order_or_return_another_customers_invoice(): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        $this->provider();
        $foreignKey = $this->checkoutKey();
        $this->post('/checkout', $this->details(['checkout_key' => $foreignKey, 'payment_option' => 'full']))->assertSessionHasNoErrors();
        $foreign = Order::sole();
        $this->buyer($variant);
        $ownKey = $this->checkoutKey();
        foreach ([$foreignKey, (string) Str::uuid()] as $invalidKey) {
            $this->postJson('/checkout', $this->details(['checkout_key' => $invalidKey, 'payment_option' => 'full']))->assertUnprocessable()->assertJsonValidationErrors('checkout_key');
        }
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(2, $variant->fresh()->reserved);
        Http::assertSentCount(1);
        $this->post('/checkout', $this->details(['checkout_key' => $ownKey, 'creation_key' => $foreignKey, 'payment_option' => 'full']))->assertSessionHasNoErrors();
        $own = Order::whereKeyNot($foreign->id)->sole();
        $this->assertSame($ownKey, $own->creation_key);
        Http::assertSentCount(2);
    }

    public function test_delivery_checkout_replay_returns_the_original_order_after_the_quote_was_consumed_and_expired(): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        $this->provider();
        $key = $this->checkoutKey();
        $address = ['street_address' => '12 Example Street', 'city' => 'Quezon City', 'province' => 'Metro Manila', 'postal_code' => '1100',
            'country' => 'Philippines', 'lat' => '14.676', 'lng' => '121.0437'];
        $this->withSession(['delivery_quote' => ['quote' => ['quotationId' => 'quote_checkout_replay', 'expiresAt' => now()->addMinutes(5)->toIso8601String(),
            'priceBreakdown' => ['currency' => 'PHP', 'total' => '125.50'], 'stops' => [['stopId' => 'pickup'], ['stopId' => 'recipient']]],
            'address' => '12 Example Street, Quezon City, Metro Manila, 1100, Philippines', 'lat' => $address['lat'], 'lng' => $address['lng']]]);
        $details = $this->details(['checkout_key' => $key, 'payment_option' => 'full', 'delivery_method' => 'lalamove']) + $address;
        $this->post('/checkout', $details)->assertSessionHasNoErrors()->assertSessionMissing('delivery_quote');
        $order = Order::sole();
        $this->travel(6)->minutes();
        $this->post('/checkout', $details)->assertRedirect(route('orders.show', $order));
        $this->assertSame(12550, $order->shipping);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(2, $variant->fresh()->reserved);
        Http::assertSentCount(1);
    }

    public static function invalidAmounts(): array
    {
        return [[null], ['0'], ['0.99'], ['-1'], ['not-money'], ['1e2'], ['10.001'], ['42949673.00'], ['1200.00'], ['1200.01']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_first_payment_cannot_create_an_invoice_reserve_stock_or_call_paymongo(mixed $amount): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        $this->post('/checkout', $this->details(['payment_amount' => $amount]))->assertSessionHasErrors()->assertSessionHas('cart', [$variant->id => 2]);
        $this->assertNoInvoiceOrReservation($variant);
    }

    public static function invalidDays(): array
    {
        return [[6], [31], [7.5], ['later']];
    }

    #[DataProvider('invalidDays')]
    public function test_invalid_customer_deadline_cannot_create_a_payment_or_reservation(mixed $days): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        $this->post('/checkout', $this->details(['payment_days' => $days]))->assertSessionHasErrors('payment_days');
        $this->assertNoInvoiceOrReservation($variant);
    }

    public static function allowedDays(): array
    {
        return [[7], [30]];
    }

    #[DataProvider('allowedDays')]
    public function test_customer_deadline_includes_seven_and_thirty_days_on_the_original_invoice(int $days): void
    {
        $variant = $this->variant();
        $this->buyer($variant);
        $this->provider();
        $this->post('/checkout', $this->details(['payment_days' => $days]))->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertTrue($order->due_at->equalTo($order->created_at->copy()->addDays($days)));
        $this->assertSame($order->getRawOriginal('due_at'), $order->getRawOriginal('original_due_at'));
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public static function thresholdSubtotals(): array
    {
        return [[99999, false], [100000, false], [100001, true]];
    }

    #[DataProvider('thresholdSubtotals')]
    public function test_checkout_exposes_partial_payment_only_above_the_strict_discounted_clothing_threshold(int $subtotal, bool $eligible): void
    {
        $variant = $this->variant($subtotal);
        $this->buyer($variant, 1);
        $response = $this->get('/checkout')->assertOk()->assertViewHas('summary', fn ($summary) => $summary['subtotal'] === $subtotal);
        if ($eligible) {
            $response->assertSee('value="partial"', false)->assertSee('name="payment_amount"', false);
        } else {
            $response->assertDontSee('value="partial"', false)->assertDontSee('name="payment_amount"', false);
            $this->post('/checkout', $this->details(['payment_amount' => '100']))->assertSessionHasErrors();
        }
        $this->assertNoInvoiceOrReservation($variant);
    }

    public function test_bulk_discount_can_make_a_gross_amount_above_the_threshold_ineligible(): void
    {
        $variant = $this->variant(2000, 1042);
        $this->buyer($variant, 101);
        $this->get('/checkout')->assertOk()->assertDontSee('value="partial"', false)
            ->assertViewHas('summary', fn ($summary) => $summary['base'] === 105242 && $summary['discount'] === 5262 && $summary['subtotal'] === 99980);
        $this->post('/checkout', $this->details(['payment_amount' => '100']))->assertSessionHasErrors();
        $this->assertNoInvoiceOrReservation($variant);
    }

    public function test_delivery_fee_cannot_make_clothing_at_the_threshold_eligible_for_partial_payment(): void
    {
        $variant = $this->variant(100000);
        $this->buyer($variant, 1);
        $address = ['street_address' => '12 Example Street', 'city' => 'Quezon City', 'province' => 'Metro Manila', 'postal_code' => '1100',
            'country' => 'Philippines', 'lat' => '14.676', 'lng' => '121.0437'];
        $quote = ['quotationId' => 'quote_customer_installment', 'priceBreakdown' => ['currency' => 'PHP', 'total' => '2000.00'],
            'expiresAt' => now()->addMinutes(5)->toIso8601String(), 'stops' => [['stopId' => 'pickup'], ['stopId' => 'recipient']]];
        $this->withSession(['delivery_quote' => ['quote' => $quote, 'address' => '12 Example Street, Quezon City, Metro Manila, 1100, Philippines',
            'lat' => $address['lat'], 'lng' => $address['lng']]])
            ->post('/checkout', $this->details(['delivery_method' => 'lalamove', 'payment_amount' => '500']) + $address)->assertSessionHasErrors();
        $this->assertNoInvoiceOrReservation($variant);
    }

    public function test_owner_deadline_default_changes_new_invoices_only_and_records_the_agreement(): void
    {
        $variant = $this->variant();
        $firstCustomer = $this->buyer($variant);
        $this->provider();
        $firstPayload = $this->details();
        unset($firstPayload['payment_days']);
        $this->post('/checkout', $firstPayload)->assertSessionHasNoErrors();
        $first = Order::sole();
        $firstDeadline = $first->getRawOriginal('due_at');
        $firstOriginal = $first->getRawOriginal('original_due_at');
        $this->assertTrue($first->due_at->equalTo($first->created_at->copy()->addDays(7)));
        $owner = User::factory()->create(['role' => 'admin']);
        $reason = 'New installment invoices will use the agreed twenty-one day deadline.';
        $this->actingAs($owner)->post('/admin/settings/payment-terms', ['days' => 21, 'reason' => $reason])->assertSessionHasNoErrors();
        $this->assertSame('21', DB::table('shop_settings')->where('key', 'partial_payment_due_days')->value('value'));
        $event = ActivityLog::where('action', 'invoice.terms_changed')->sole();
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame(['reason' => $reason, 'days' => 21], $event->metadata);
        $this->assertSame($firstDeadline, $first->fresh()->getRawOriginal('due_at'));
        $this->assertSame($firstOriginal, $first->fresh()->getRawOriginal('original_due_at'));
        $secondVariant = $this->variant();
        $secondCustomer = $this->buyer($secondVariant);
        $this->get('/checkout')->assertOk()->assertSee('value="21" selected', false);
        $this->post('/checkout', array_replace($firstPayload, ['phone' => '09987654321']))->assertSessionHasNoErrors();
        $second = Order::where('user_id', $secondCustomer->id)->sole();
        $this->assertTrue($second->due_at->equalTo($second->created_at->copy()->addDays(21)));
        $this->assertSame($second->getRawOriginal('due_at'), $second->getRawOriginal('original_due_at'));
        $this->assertSame($firstCustomer->id, $first->user_id);
        $this->assertDatabaseCount('orders', 2);
        Http::assertSentCount(2);
    }

    public static function invalidOwnerTerms(): array
    {
        return [[6, 'The deadline must stay within the allowed range.'], [31, 'The deadline must stay within the allowed range.'], [14, ''], [14, 'short']];
    }

    #[DataProvider('invalidOwnerTerms')]
    public function test_owner_terms_require_a_reason_and_a_seven_to_thirty_day_default(int $days, string $reason): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/settings/payment-terms', ['days' => $days, 'reason' => $reason])->assertSessionHasErrors();
        $this->assertSame('7', DB::table('shop_settings')->where('key', 'partial_payment_due_days')->value('value'));
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_staff_and_customers_cannot_change_owner_installment_defaults(): void
    {
        foreach (['staff', 'customer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->post('/admin/settings/payment-terms',
                ['days' => 21, 'reason' => 'Only the owner can change installment defaults.'])->assertForbidden();
        }
        $this->assertSame('7', DB::table('shop_settings')->where('key', 'partial_payment_due_days')->value('value'));
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }
}
