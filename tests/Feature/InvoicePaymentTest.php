<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use App\Services\InvoicePayments;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_invoice', 'shop.paymongo.webhook_secret' => 'invoice-secret']);
        Http::preventStrayRequests();
    }

    private function invoice(bool $walkIn = false, bool $guest = false, int $quantity = 5): Order
    {
        $buyer = $guest ? null : User::factory()->create(['role' => 'customer']);
        $product = Product::create(['name' => 'Invoice Tee', 'slug' => 'invoice-'.Str::random(8), 'category' => '', 'description' => 'Test fixture']);
        $variant = $product->variants()->create(['sku' => (string) Str::uuid(), 'size' => 'M', 'color' => 'White', 'stock' => 1000, 'retail_price' => 25000, 'wholesale_price' => 20000, 'reseller_price' => 18000]);

        return app(OrderFlow::class)->reserve([$variant->id => $quantity], ['recipient' => 'Invoice buyer', 'phone' => '09'.str_pad((string) ($buyer?->id ?? 99), 9, '0', STR_PAD_LEFT), 'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0,
            'sales_channel' => $walkIn ? 'walk_in' : 'online', 'payment_plan' => 'partial', 'due_at' => now()->addDays(7)], $buyer?->id)->fresh();
    }

    private function checkout(): void
    {
        $i = 0;
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => function () use (&$i) {
            $id = 'cs_invoice_'.++$i;

            return Http::response(['data' => ['id' => $id, 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/'.$id]]]);
        }]);
    }

    private function webhook(PaymentAttempt $attempt, string $event, string $paymentId, ?int $amount = null)
    {
        $body = json_encode(['data' => ['id' => $event, 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => ['id' => $attempt->checkout_session_id, 'attributes' => [
            'reference_number' => $attempt->order->number, 'payments' => [['id' => $paymentId, 'attributes' => ['status' => 'paid', 'currency' => 'PHP', 'amount' => $amount ?? $attempt->amount, 'paid_at' => now()->timestamp]]],
        ]]]]]);
        $time = (string) time();

        return $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => 't='.$time.',te='.hash_hmac('sha256', $time.'.'.$body, 'invoice-secret')], $body);
    }

    public function test_two_verified_online_payments_reduce_the_same_invoice_without_changing_deadline_or_committing_stock_early(): void
    {
        $order = $this->invoice();
        $original = $order->only(['number', 'total', 'subtotal', 'discount', 'due_at', 'original_due_at']);
        $this->checkout();
        $first = app(InvoicePayments::class)->start($order, 50000, $order->user, (string) Str::uuid());
        $this->webhook($first, 'evt_deposit', 'pay_deposit')->assertOk()->assertJsonPath('outcome', 'partially_paid');
        $order->refresh();
        $this->assertSame(50000, $order->amount_paid);
        $this->assertSame(75000, $order->outstandingBalance());
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertFalse($order->inventory_committed);
        $this->assertSame(1000, Variant::sole()->stock);
        $this->assertSame(5, Variant::sole()->reserved);
        $this->assertNull($order->paid_at);
        $this->webhook($first, 'evt_deposit', 'pay_deposit')->assertOk();
        $this->webhook($first, 'evt_deposit_repeat', 'pay_deposit')->assertOk();
        $this->assertSame(50000, $order->fresh()->amount_paid);
        $second = app(InvoicePayments::class)->start($order, 75000, $order->user, (string) Str::uuid());
        $this->webhook($second, 'evt_balance', 'pay_balance')->assertOk()->assertJsonPath('outcome', 'paid');
        $order->refresh();
        $this->assertSame('packing', $order->status);
        $this->assertSame(125000, $order->paidAmount());
        $this->assertSame(0, $order->outstandingBalance());
        $this->assertSame(995, Variant::sole()->stock);
        $this->assertSame(0, Variant::sole()->reserved);
        foreach (['number', 'total', 'subtotal', 'discount'] as $field) {
            $this->assertSame($original[$field], $order->$field);
        }
        $this->assertTrue($original['due_at']->equalTo($order->due_at));
        $this->assertTrue($original['original_due_at']->equalTo($order->original_due_at));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 2);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['data']['attributes']['reference_number'] === $order->number && $r['data']['attributes']['line_items'][0]['amount'] === 75000);
    }

    public function test_mixed_cash_and_online_expiry_keeps_deposit_stock_and_deadline_then_full_cash_closes_walk_in(): void
    {
        $order = $this->invoice(true);
        $staff = User::factory()->create(['role' => 'staff']);
        $deadline = $order->getRawOriginal('due_at');
        $key = (string) Str::uuid();
        $cash = ['amount' => '300', 'reason' => 'Cash deposit counted and received at the counter.', 'request_key' => $key, 'payment_received' => '1'];
        $this->actingAs($staff)->post('/admin/orders/'.$order->id.'/payments/cash', $cash)->assertSessionHasNoErrors();
        $this->post('/admin/orders/'.$order->id.'/payments/cash', $cash)->assertSessionHasNoErrors();
        $this->assertSame(30000, $order->fresh()->amount_paid);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->checkout();
        $attempt = app(InvoicePayments::class)->start($order, 95000, $staff, (string) Str::uuid());
        $this->postJson('/admin/orders/'.$order->id.'/payments/cash', array_replace($cash, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/'.$attempt->checkout_session_id => Http::response(['data' => [
            'id' => $attempt->checkout_session_id, 'attributes' => ['reference_number' => $order->number, 'livemode' => false, 'line_items' => [['amount' => 95000, 'quantity' => 1, 'currency' => 'PHP']],
                'status' => 'expired', 'payments' => [], 'payment_intent' => null],
        ]])]);
        $this->post('/orders/'.$order->id.'/payment/close')->assertSessionHasNoErrors();
        $this->assertSame('partially_paid', $order->fresh()->payment_status);
        $this->assertFalse($order->fresh()->inventory_released);
        $this->assertSame('expired', $attempt->fresh()->status);
        $this->assertSame(5, Variant::sole()->reserved);
        $this->assertSame($deadline, $order->fresh()->getRawOriginal('due_at'));
        $this->post('/admin/orders/'.$order->id.'/payments/cash', array_replace($cash, ['amount' => '950', 'request_key' => (string) Str::uuid()]))->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('Delivered', $order->customerDeliveryLabel());
        $this->assertNotNull($order->walk_in_received_at);
        $this->assertNull($order->customer_confirmed_at);
        $this->assertDatabaseCount('order_reviews', 0);
        $this->assertSame(995, Variant::sole()->stock);
        $this->assertSame(2, ActivityLog::where('action', 'invoice.payment_received')->count());
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/refunds', ['reason' => 'The clothing did not fit after purchase.'])->assertSessionHasNoErrors();
    }

    public static function badAmounts(): array
    {
        return [['0'], ['-1'], ['1250.01'], ['1.001'], ['1e3'], ['NaN']];
    }

    #[DataProvider('badAmounts')]
    public function test_invalid_or_excess_cash_cannot_change_invoice(string $amount): void
    {
        $order = $this->invoice();
        $this->actingAs(User::factory()->create(['role' => 'staff']))->postJson('/admin/orders/'.$order->id.'/payments/cash', [
            'amount' => $amount, 'reason' => 'Cash was received at the counter.', 'payment_received' => '1', 'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $this->assertSame(0, $order->fresh()->amount_paid);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_cash_requires_actual_receipt_confirmation_and_customer_cannot_record_it(): void
    {
        $order = $this->invoice();
        $payload = ['amount' => '500', 'reason' => 'Cash was received at the counter.', 'request_key' => (string) Str::uuid()];
        $this->actingAs($order->user)->postJson('/admin/orders/'.$order->id.'/payments/cash', $payload + ['payment_received' => '1'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'staff']))->postJson('/admin/orders/'.$order->id.'/payments/cash', $payload)->assertUnprocessable()->assertJsonValidationErrors('payment_received');
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_another_customer_cannot_pay_or_close_someone_elses_invoice(): void
    {
        $order = $this->invoice();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->postJson('/orders/'.$order->id.'/payments', ['amount' => '500', 'request_key' => (string) Str::uuid(), 'user_id' => $order->user_id])->assertForbidden();
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_checkout_timeout_keeps_one_unknown_attempt_and_blocks_a_second_payment(): void
    {
        $order = $this->invoice();
        Http::fake(fn () => throw new ConnectionException('Simulated timeout'));
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payments', ['amount' => '500', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('payment');
        $this->assertSame('unknown', $order->payments()->sole()->status);
        $this->postJson('/orders/'.$order->id.'/payments', ['amount' => '500', 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(0, $order->fresh()->amount_paid);
        $this->assertFalse($order->fresh()->inventory_released);
    }

    public function test_provider_wrong_amount_cannot_turn_a_deposit_into_full_payment(): void
    {
        $order = $this->invoice();
        $this->checkout();
        $attempt = app(InvoicePayments::class)->start($order, 50000, $order->user, (string) Str::uuid());
        $this->webhook($attempt, 'evt_wrong', 'pay_wrong', 125000)->assertConflict();
        $this->assertSame(0, $order->fresh()->amount_paid);
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_owner_deadline_agreement_is_logged_and_payment_never_moves_original_deadline(): void
    {
        $order = $this->invoice();
        $original = $order->getRawOriginal('original_due_at');
        $payload = ['due_at' => now()->addDays(14)->toDateString(), 'reason' => 'Customer and owner agreed to settle in fourteen days.'];
        $this->actingAs(User::factory()->create(['role' => 'staff']))->postJson('/admin/orders/'.$order->id.'/due', $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/orders/'.$order->id.'/due', $payload)->assertSessionHasNoErrors();
        $this->assertSame($original, $order->fresh()->getRawOriginal('original_due_at'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'invoice.due_changed', 'target_id' => $order->id]);
        $this->postJson('/admin/orders/'.$order->id.'/due', array_replace($payload, ['due_at' => now()->addDays(31)->toDateString()]))->assertUnprocessable();
    }

    public function test_cash_audit_failure_rolls_back_payment_receipt_and_stock(): void
    {
        $order = $this->invoice(true);
        $this->mock(ActivityRecorder::class, fn ($mock) => $mock->shouldReceive('record')->once()->andReturnNull());
        $this->actingAs(User::factory()->create(['role' => 'staff']))->post('/admin/orders/'.$order->id.'/payments/cash', [
            'amount' => '1250', 'reason' => 'Full cash payment counted and received.', 'payment_received' => '1', 'request_key' => (string) Str::uuid(),
        ])->assertServerError();
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $order->fresh()->amount_paid);
        $this->assertSame(1000, Variant::sole()->stock);
        $this->assertSame(5, Variant::sole()->reserved);
    }

    public function test_guest_invoice_is_private_signed_and_only_scoped_to_guest_walk_in(): void
    {
        $order = $this->invoice(true, true);
        $this->get('/guest/invoices/'.$order->id)->assertForbidden();
        $url = URL::temporarySignedRoute('guest.orders.show', now()->addHour(), ['order' => $order->id]);
        $this->get($url)->assertOk()->assertSee($order->number)->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $foreign = $this->invoice();
        $this->get(str_replace('/'.$order->id.'?', '/'.$foreign->id.'?', $url))->assertForbidden();
        $this->get(URL::temporarySignedRoute('guest.orders.show', now()->subMinute(), ['order' => $order->id]))->assertForbidden();
        $this->get(URL::temporarySignedRoute('guest.orders.show', now()->addHour(), ['order' => $foreign->id]))->assertNotFound();
        $this->checkout();
        $this->post(URL::temporarySignedRoute('guest.orders.pay', now()->addHour(), ['order' => $order->id]), ['amount' => '500', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertNull($order->payments()->sole()->recorded_by);
        $this->assertNull($order->user_id);
        $this->assertDatabaseCount('users', 1);
    }

    public static function rejectedCreations(): array
    {
        return [[401, false], [422, false], [401, true], [422, true]];
    }

    #[DataProvider('rejectedCreations')]
    public function test_definitively_rejected_checkout_can_be_retried_without_trapping_cash_or_losing_deposits(int $status, bool $deposit): void
    {
        $order = $this->invoice();
        $staff = User::factory()->create(['role' => 'staff']);
        if ($deposit) {
            app(InvoicePayments::class)->recordCash($order, 30000, $staff, 'Cash deposit counted and received.', (string) Str::uuid());
        }
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::response(['errors' => [['code' => 'invalid_request', 'detail' => 'Controlled rejection without a session.']]], $status)]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/payments', ['amount' => '500', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasErrors('payment');
        $this->assertSame('failed', $order->payments()->where('method', 'paymongo')->sole()->status);
        $page = $this->get('/orders/'.$order->id)->assertOk();
        if ($deposit) {
            $page->assertDontSee('Close unpaid invoice');
        } else {
            $page->assertSee('Close unpaid invoice');
        }
        $deadline = $order->fresh()->getRawOriginal('due_at');
        $this->post('/orders/'.$order->id.'/payment/close')->assertRedirect()->assertSessionHasNoErrors();
        if (! $deposit) {
            $this->assertTrue($order->fresh()->inventory_released);
            $this->assertSame(0, Variant::sole()->reserved);
            $this->assertSame(0, $order->fresh()->amount_paid);
        } else {
            $this->assertFalse($order->fresh()->inventory_released);
            $this->assertSame(30000, $order->fresh()->amount_paid);
            $this->assertSame(5, Variant::sole()->reserved);
            $this->actingAs($staff)->post('/admin/orders/'.$order->id.'/payments/cash', ['amount' => '950', 'request_key' => (string) Str::uuid(), 'reason' => 'Remaining cash balance counted and received.', 'payment_received' => '1'])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('paid', $order->fresh()->payment_status);
            $this->assertSame(125000, $order->fresh()->amount_paid);
        }
        $this->assertSame($deadline, $order->fresh()->getRawOriginal('due_at'));
        Http::assertSentCount(1);
    }
}
