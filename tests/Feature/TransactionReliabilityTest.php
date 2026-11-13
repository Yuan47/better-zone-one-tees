<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoicePayments;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransactionReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_transaction',
            'shop.paymongo.webhook_secret' => 'transaction-secret']);
        Http::preventStrayRequests();
    }

    private function order(int $quantity = 2): Order
    {
        $product = Product::create(['name' => 'Transaction tee', 'slug' => 'transaction-'.Str::random(8),
            'description' => 'Isolated test fixture', 'category' => '']);
        $variant = $product->variants()->create(['sku' => (string) Str::uuid(), 'size' => 'M', 'color' => 'White',
            'stock' => 10, 'retail_price' => 20000, 'wholesale_price' => 18000, 'reseller_price' => 16000]);

        return app(OrderFlow::class)->reserve([$variant->id => $quantity], ['recipient' => 'Transaction buyer',
            'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0],
            User::factory()->create(['role' => 'customer'])->id);
    }

    private function payment(Order $order): array
    {
        return ['id' => 'pay_shared_deposit', 'attributes' => ['status' => 'paid', 'currency' => 'PHP',
            'amount' => $order->total, 'livemode' => false, 'paid_at' => now()->timestamp]];
    }

    private function webhook(Order $order, string $event, array $payment)
    {
        $body = json_encode(['data' => ['id' => $event, 'attributes' => ['type' => 'checkout_session.payment.paid',
            'livemode' => false, 'data' => ['id' => $order->checkout_session_id,
                'attributes' => ['reference_number' => $order->number, 'payments' => [$payment]]]]]]);
        $time = (string) time();

        return $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => 't='.$time.',te='.hash_hmac('sha256', $time.'.'.$body, 'transaction-secret')], $body);
    }

    public static function invalidCreatedSessionIds(): array
    {
        return [['not_a_checkout'], [123], [['id' => 'cs_nested']], ['cs_'.str_repeat('a', 121)], ["cs_invalid\n"]];
    }

    public static function validCreatedSessionIds(): array
    {
        // The mixed-case example is from PayMongo's Checkout Session Resource.
        return [['cs_CbFCTDfxvMFNjwjVi26Uzhtj'], ['cs_'.str_repeat('a', 120)]];
    }

    #[DataProvider('validCreatedSessionIds')]
    public function test_supported_created_checkout_ids_keep_the_original_checkout_on_retry(string $id): void
    {
        $order = $this->order();
        $url = 'https://checkout.paymongo.com/'.$id;
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => ['id' => $id,
            'attributes' => ['checkout_url' => $url]]])]);
        $payload = ['amount' => '400', 'request_key' => (string) Str::uuid()];
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payments', $payload)
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $this->post('/orders/'.$order->id.'/payments', $payload)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame('pending', $order->payments()->sole()->status);
        $this->assertSame($id, $order->fresh()->checkout_session_id);
        $this->assertSame($id, $order->payments()->sole()->checkout_session_id);
        $this->assertSame(10, $order->items()->sole()->variant->stock);
        $this->assertSame(2, $order->items()->sole()->variant->reserved);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidCreatedSessionIds')]
    public function test_malformed_created_checkout_stays_uncertain_and_can_be_recovered_by_owner(mixed $id): void
    {
        $order = $this->order();
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => ['id' => $id,
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_real']]])]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payments', ['amount' => '400',
            'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasErrors('payment');
        $attempt = $order->payments()->sole();
        $this->assertSame('unknown', $attempt->status);
        $this->assertNull($attempt->checkout_session_id);
        $this->assertNull($order->fresh()->checkout_session_id);
        $this->postJson('/orders/'.$order->id.'/payments', ['amount' => '400', 'request_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
        Http::assertSentCount(1);

        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_recovered_transaction' => Http::response(['data' => [
            'id' => 'cs_recovered_transaction', 'attributes' => ['reference_number' => $order->number, 'livemode' => false,
                'metadata' => ['invoice_payment_reference' => $attempt->reference],
                'line_items' => [['amount' => $order->total, 'quantity' => 1, 'currency' => 'PHP']],
                'payments' => [$this->payment($order)], 'status' => 'active'],
        ]])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/orders/'.$order->id.'/payment/recover',
            ['checkout_session_id' => 'cs_recovered_transaction'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame(40000, $order->fresh()->amount_paid);
        $this->assertSame(8, $order->items()->sole()->variant->stock);
        $this->assertSame(0, $order->items()->sole()->variant->reserved);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_callback_before_checkout_save_can_retry_and_cash_cannot_overtake_the_claimed_online_payment(): void
    {
        $order = $this->order();
        $staff = User::factory()->create(['role' => 'staff']);
        $callbackOrder = $order->fresh();
        $callbackOrder->checkout_session_id = 'cs_creation_race';
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => function () use ($order, $staff, $callbackOrder) {
            $this->webhook($callbackOrder, 'evt_creation_race', $this->payment($order))
                ->assertConflict()->assertJsonPath('outcome', 'unknown_order');
            $this->assertDatabaseCount('webhook_events', 0);
            try {
                app(InvoicePayments::class)->recordCash($order, $order->total, $staff,
                    'Full cash amount counted at the counter.', (string) Str::uuid());
                $this->fail('A creating online checkout must prevent a competing cash receipt.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('payment', $exception->errors());
            }
            $this->assertSame('creating', $order->payments()->sole()->status);

            return Http::response(['data' => ['id' => 'cs_creation_race',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_creation_race']]]);
        }]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payments', ['amount' => '400',
            'request_key' => (string) Str::uuid()])->assertRedirect('https://checkout.paymongo.com/cs_creation_race');
        $this->webhook($callbackOrder, 'evt_creation_race', $this->payment($order))->assertOk()->assertJsonPath('outcome', 'paid');
        $this->webhook($callbackOrder, 'evt_creation_race', $this->payment($order))->assertOk()->assertJsonPath('outcome', 'duplicate');
        $this->webhook($callbackOrder, 'evt_creation_race_repeated', $this->payment($order))->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame($order->total, $order->fresh()->amount_paid);
        $this->assertSame(8, $order->items()->sole()->variant->stock);
        $this->assertSame(0, $order->items()->sole()->variant->reserved);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertSentCount(1);
    }

    public static function legacyConfirmationMethods(): array
    {
        return [['webhook', 'deposit'], ['check', 'deposit'], ['webhook', 'legacy_order'], ['check', 'legacy_order']];
    }

    #[DataProvider('legacyConfirmationMethods')]
    public function test_legacy_confirmation_cannot_reuse_a_payment_already_received_on_another_invoice(string $method, string $sourceType): void
    {
        $source = $this->order($sourceType === 'deposit' ? 4 : 2);
        $deposit = null;
        if ($sourceType === 'deposit') {
            $deposit = $source->payments()->create(['reference' => (string) Str::uuid(), 'method' => 'paymongo', 'status' => 'paid',
                'amount' => 40000, 'payment_id' => 'pay_shared_deposit', 'received_at' => now()->subMinute()]);
            $source->update(['payment_plan' => 'partial', 'amount_paid' => 40000, 'payment_status' => 'partially_paid']);
        } else {
            DB::transaction(fn () => app(OrderFlow::class)->paid(Order::whereKey($source->id)->lockForUpdate()->firstOrFail(), 'pay_shared_deposit'));
        }
        $order = $this->order();
        $order->update(['checkout_session_id' => 'cs_legacy_transaction']);
        $beforeSource = $source->fresh()->getAttributes();
        $beforeDeposit = $deposit?->fresh()->getAttributes();
        if ($method === 'webhook') {
            $this->webhook($order, 'evt_shared_payment', $this->payment($order))->assertConflict()->assertJsonPath('outcome', 'mismatch');
        } else {
            Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_legacy_transaction' => Http::response(['data' => [
                'id' => $order->checkout_session_id, 'attributes' => ['reference_number' => $order->number, 'livemode' => false,
                    'line_items' => [['amount' => $order->total, 'quantity' => 1, 'currency' => 'PHP']],
                    'payments' => [$this->payment($order)], 'status' => 'active'],
            ]])]);
            $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/payment/check')
                ->assertUnprocessable()->assertJsonValidationErrors('payment');
        }
        $this->assertSame($beforeSource, $source->fresh()->getAttributes());
        $this->assertSame($beforeDeposit, $deposit?->fresh()->getAttributes());
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0, $order->fresh()->amount_paid);
        $this->assertNull($order->fresh()->payment_id);
        $this->assertFalse($order->fresh()->inventory_committed);
        $this->assertSame(10, $order->items()->sole()->variant->stock);
        $this->assertSame(2, $order->items()->sole()->variant->reserved);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('payment_attempts', $sourceType === 'deposit' ? 1 : 0);
    }
}
