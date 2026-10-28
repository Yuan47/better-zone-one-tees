<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceiptNotification;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\InvoicePayments;
use App\Services\OrderFlow;
use App\Services\PaymentReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentReceiptNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'services.brevo.payment_receipts_enabled' => true,
            'services.brevo.payment_receipts_from' => now()->subDay()->format('Y-m-d\TH:i:sP'),
            'services.brevo.api_key' => 'receipt-test-key', 'services.brevo.from_email' => 'store@example.test',
            'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
    }

    private function invoice(): Order
    {
        $buyer = User::factory()->create(['role' => 'customer', 'name' => '<script>Receipt buyer</script>',
            'email' => User::where('email', 'buyer@example.test')->exists() ? 'buyer-'.Str::random(10).'@example.test' : 'buyer@example.test']);
        $product = Product::create(['name' => 'Receipt test shirt', 'slug' => 'receipt-'.Str::uuid(), 'category' => '', 'description' => 'Test only']);
        $variant = $product->variants()->create(['sku' => 'RECEIPT-'.Str::uuid(), 'size' => 'M', 'color' => 'White', 'stock' => 20,
            'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);

        return app(OrderFlow::class)->reserve([$variant->id => 12], ['recipient' => 'Receipt buyer', 'phone' => '0912'.str_pad((string) $buyer->id, 7, '0', STR_PAD_LEFT),
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0, 'payment_plan' => 'partial',
            'due_at' => now()->addDays(14)], $buyer->id)->fresh();
    }

    private function paid(Order $order, int $amount): PaymentAttempt
    {
        $attempt = $order->payments()->create(['reference' => (string) Str::uuid(), 'method' => 'paymongo', 'status' => 'pending',
            'amount' => $amount, 'checkout_session_id' => 'cs_receipt_'.Str::random(12)]);
        app(InvoicePayments::class)->receiveProvider($attempt, $this->provider($attempt));

        return $attempt->fresh();
    }

    private function provider(PaymentAttempt $payment): array
    {
        return ['id' => 'pay_receipt_'.$payment->id, 'attributes' => ['status' => 'paid', 'currency' => 'PHP',
            'amount' => $payment->amount, 'livemode' => config('shop.paymongo.mode') === 'live', 'paid_at' => now()->timestamp]];
    }

    private function snapshot(Order $order): array
    {
        return ['order' => $order->fresh()->getAttributes(), 'payments' => $order->payments()->orderBy('id')->get()->map->getAttributes()->all(),
            'variants' => Variant::orderBy('id')->get()->map->getAttributes()->all(), 'items' => $order->items()->orderBy('id')->get()->map->getAttributes()->all()];
    }

    private function accepted(): void
    {
        Http::fake(['api.brevo.com/v3/smtp/email' => fn () => Http::response(['messageId' => '<receipt-'.Str::uuid().'@brevo.test>'], 201)]);
    }

    public function test_deposit_and_final_balance_have_separate_store_receipts_without_changing_invoice_or_stock(): void
    {
        $order = $this->invoice();
        $deposit = $this->paid($order, 30000);
        $balance = $this->paid($order->fresh(), 78000);
        // Confirming either real payment never sends mail inline.
        Http::assertNothingSent();
        $before = $this->snapshot($order);
        $transportOptions = [];
        Http::fake(['api.brevo.com/v3/smtp/email' => function ($request, $options) use (&$transportOptions) {
            $transportOptions[] = $options;

            return Http::response(['messageId' => '<receipt-'.Str::uuid().'@brevo.test>'], 201);
        }]);
        $result = app(PaymentReceipts::class)->run();
        $this->assertSame(2, $result['accepted']);
        $this->assertSame($before, $this->snapshot($order));
        $rows = PaymentReceiptNotification::orderBy('payment_attempt_id')->get();
        $this->assertSame([$deposit->id, $balance->id], $rows->pluck('payment_attempt_id')->all());
        $this->assertSame([30000, 78000], $rows->pluck('amount')->all());
        $this->assertSame([78000, 0], $rows->pluck('balance_after')->all());
        $this->assertSame([$deposit->reference, $balance->reference], $rows->pluck('receipt_reference')->all());
        $this->assertCount(2, $rows->pluck('claim_key')->unique());
        $this->assertStringContainsString('inbox delivery not confirmed', $rows->first()->label());
        foreach ($transportOptions as $options) {
            $this->assertFalse($options['allow_redirects'] ?? true);
        }
        Http::assertSent(function ($request) {
            $this->assertSame('buyer@example.test', $request['to'][0]['email']);
            $this->assertSame(['store-payment-receipt'], $request['tags']);
            $this->assertStringContainsString('TEST — NO REAL MONEY', $request['subject']);
            $this->assertStringContainsString('&lt;script&gt;Receipt buyer&lt;/script&gt;', $request['htmlContent']);
            $this->assertStringNotContainsString('<script>Receipt buyer</script>', $request['htmlContent']);
            $this->assertStringContainsString('store-issued payment acknowledgment', $request['textContent']);

            return true;
        });
        app(InvoicePayments::class)->receiveProvider($balance, $this->provider($balance));
        $this->assertSame(0, app(PaymentReceipts::class)->run()['accepted']);
        $this->assertSame($before, $this->snapshot($order));
        $this->assertDatabaseCount('payment_receipt_notifications', 2);
        Http::assertSentCount(2);
    }

    public function test_automatic_cutoff_prevents_historical_mass_email_but_one_reasoned_backfill_is_allowed(): void
    {
        $order = $this->invoice();
        $payment = $this->paid($order, 30000);
        config(['services.brevo.payment_receipts_from' => now()->addHour()->format('Y-m-d\TH:i:sP')]);
        $this->accepted();
        $this->assertSame(0, app(PaymentReceipts::class)->run()['accepted']);
        $this->assertDatabaseCount('payment_receipt_notifications', 0);
        $this->assertSame(1, app(PaymentReceipts::class)->run($payment->id, reason: 'Customer reported a missing earlier receipt.')['accepted']);
        $this->assertSame('Customer reported a missing earlier receipt.', PaymentReceiptNotification::sole()->backfill_reason);
        Http::assertSentCount(1);
    }

    public function test_committed_queued_backfill_is_resumed_after_interruption_without_second_claim(): void
    {
        $order = $this->invoice();
        $payment = $this->paid($order, 30000);
        config(['services.brevo.payment_receipts_from' => now()->addHour()->format('Y-m-d\TH:i:sP')]);
        $queued = PaymentReceiptNotification::create(['payment_attempt_id' => $payment->id, 'order_id' => $order->id, 'claim_key' => (string) Str::uuid(),
            'status' => 'queued', 'receipt_reference' => $payment->reference, 'payment_id' => $payment->payment_id, 'payment_mode' => 'test',
            'amount' => 30000, 'balance_after' => 78000, 'received_at' => $payment->received_at, 'backfill_reason' => 'Approved earlier single-receipt backfill.']);
        config(['shop.paymongo.mode' => 'live']);
        $this->accepted();
        $this->assertSame(1, app(PaymentReceipts::class)->run()['accepted']);
        $this->assertSame($queued->claim_key, PaymentReceiptNotification::sole()->claim_key);
        $this->assertSame(1, PaymentReceiptNotification::sole()->attempts);
        Http::assertSent(fn ($request) => str_contains($request['subject'], 'TEST — NO REAL MONEY'));
        Http::assertSentCount(1);
    }

    public function test_dry_run_and_disabled_flag_make_no_email_or_delivery_claim(): void
    {
        $order = $this->invoice();
        $this->paid($order, 30000);
        $this->assertSame(1, app(PaymentReceipts::class)->run(dryRun: true)['eligible']);
        config(['services.brevo.payment_receipts_enabled' => false]);
        $this->assertFalse(app(PaymentReceipts::class)->run()['enabled']);
        $this->assertDatabaseCount('payment_receipt_notifications', 0);
        Http::assertNothingSent();
    }

    public static function invalidCutoffs(): iterable
    {
        foreach ([null, '', 'tomorrow', '2026-02-30T12:00:00+08:00', '2026-10-28 12:00:00', '2026-10-28T12:00:00Z'] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('invalidCutoffs')]
    public function test_invalid_activation_cutoff_fails_closed(mixed $value): void
    {
        $order = $this->invoice();
        $this->paid($order, 30000);
        config(['services.brevo.payment_receipts_from' => $value]);
        $this->assertFalse(app(PaymentReceipts::class)->ready());
        $this->assertSame(0, app(PaymentReceipts::class)->run()['accepted']);
        $this->assertDatabaseCount('payment_receipt_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_pending_invalid_legacy_cash_and_unverified_customer_receipts_are_not_emailed(): void
    {
        foreach (['pending', 'failed', 'expired', 'unknown', 'creating'] as $status) {
            $order = $this->invoice();
            $order->payments()->create(['reference' => (string) Str::uuid(), 'method' => 'paymongo', 'status' => $status, 'amount' => 30000]);
        }
        foreach (['payment_id', 'received_at', 'legacy', 'method', 'unverified'] as $invalid) {
            $order = $this->invoice();
            $payment = $this->paid($order, 30000);
            match ($invalid) {
                'payment_id' => $payment->update(['payment_id' => null]),
                'received_at' => $payment->update(['received_at' => null]),
                'legacy' => $payment->update(['legacy' => true]),
                'method' => $payment->update(['method' => 'cash']),
                'unverified' => $order->user->forceFill(['email_verified_at' => null])->save(),
            };
        }
        $this->assertSame(0, app(PaymentReceipts::class)->run()['accepted']);
        $this->assertDatabaseCount('payment_receipt_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_recipient_is_saved_customer_not_contact_override_and_guest_uses_only_recorded_contact(): void
    {
        $order = $this->invoice();
        $order->update(['contact_email' => 'attacker@example.test']);
        $this->paid($order, 30000);
        $guest = $this->invoice();
        $guest->update(['user_id' => null, 'sales_channel' => 'walk_in', 'contact_email' => 'recorded-guest@example.test']);
        $this->paid($guest->fresh(), 30000);
        $this->accepted();
        app(PaymentReceipts::class)->run();
        $recipients = PaymentReceiptNotification::orderBy('id')->pluck('recipient')->all();
        $this->assertSame(['buyer@example.test', 'recorded-guest@example.test'], $recipients);
        Http::assertSentCount(2);
    }

    public static function uncertainResponses(): iterable
    {
        yield 'redirect' => [302, ['messageId' => '<redirect@brevo.test>']];
        yield 'timeout response' => [408, []];
        yield 'server error' => [500, []];
        yield 'missing message ID' => [201, []];
        yield 'unsupported acceptance' => [202, ['messageId' => '<unsupported@brevo.test>']];
    }

    #[DataProvider('uncertainResponses')]
    public function test_uncertain_acceptance_blocks_automatic_and_explicit_rejected_retry(int $status, array $body): void
    {
        $order = $this->invoice();
        $payment = $this->paid($order, 30000);
        $before = $this->snapshot($order);
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::response($body, $status, ['Location' => 'https://other.example.test'])]);
        $this->assertSame(1, app(PaymentReceipts::class)->run()['unknown']);
        $this->assertSame('unknown', PaymentReceiptNotification::sole()->status);
        $this->assertSame(1, app(PaymentReceipts::class)->run()['review_required']);
        app(PaymentReceipts::class)->run($payment->id, reason: 'Checking an uncertain result must not resend.', retryRejected: true);
        $this->assertSame($before, $this->snapshot($order));
        $this->assertNull(PaymentReceiptNotification::sole()->accepted_at);
        Http::assertSentCount(1);
    }

    public function test_connection_timeout_keeps_payment_and_one_uncertain_email_claim(): void
    {
        $order = $this->invoice();
        $this->paid($order, 30000);
        $before = $this->snapshot($order);
        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;
            throw new ConnectionException('Synthetic timeout.');
        });
        $this->assertSame(1, app(PaymentReceipts::class)->run()['unknown']);
        app(PaymentReceipts::class)->run();
        $this->assertSame(1, $requests);
        $this->assertSame('unknown', PaymentReceiptNotification::sole()->status);
        $this->assertSame($before, $this->snapshot($order));
    }

    public function test_duplicate_idempotency_response_is_uncertain_and_cannot_authorize_another_email(): void
    {
        $order = $this->invoice();
        $payment = $this->paid($order, 30000);
        $before = $this->snapshot($order);
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::response(['code' => 'duplicate_parameter', 'message' => 'Duplicate idempotency key.'], 400)]);
        $this->assertSame(1, app(PaymentReceipts::class)->run()['unknown']);
        $notification = PaymentReceiptNotification::sole();
        $this->assertSame('unknown', $notification->status);
        $this->assertSame(400, $notification->provider_status);
        $this->assertNull($notification->last_rejected_status);
        $this->assertNull($notification->provider_message_id);
        $this->assertSame(0, app(PaymentReceipts::class)->run()['accepted']);
        $this->assertSame(0, app(PaymentReceipts::class)->run($payment->id, reason: 'An idempotency response is not a definitive rejection.', retryRejected: true)['accepted']);
        $this->assertSame($before, $this->snapshot($order));
        Http::assertSentCount(1);
    }

    public function test_definitive_rejection_needs_a_reasoned_single_receipt_retry(): void
    {
        $order = $this->invoice();
        $payment = $this->paid($order, 30000);
        $before = $this->snapshot($order);
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::sequence()->push([], 400)->push(['messageId' => '<retry@brevo.test>'], 201)]);
        $this->assertSame(1, app(PaymentReceipts::class)->run()['failed']);
        $this->assertSame(0, app(PaymentReceipts::class)->run()['accepted']);
        $this->assertSame(1, app(PaymentReceipts::class)->run($payment->id, reason: 'Sender setup corrected after the definitive rejection.', retryRejected: true)['accepted']);
        $notification = PaymentReceiptNotification::sole();
        $this->assertSame(2, $notification->attempts);
        $this->assertSame(400, $notification->last_rejected_status);
        $this->assertNotNull($notification->retry_reason);
        $this->assertSame($before, $this->snapshot($order));
        Http::assertSentCount(2);
    }

    public function test_reusing_a_provider_message_id_cannot_mark_two_receipts_accepted(): void
    {
        $order = $this->invoice();
        $this->paid($order, 30000);
        $this->paid($order->fresh(), 78000);
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<duplicate@brevo.test>'], 201)]);
        $result = app(PaymentReceipts::class)->run();
        $this->assertSame(1, $result['accepted']);
        $this->assertSame(1, $result['unknown']);
        $this->assertDatabaseCount('payment_receipt_notifications', 2);
        app(PaymentReceipts::class)->run();
        Http::assertSentCount(2);
    }

    public function test_persistence_failure_after_provider_acceptance_preserves_claim_and_cannot_resend(): void
    {
        $order = $this->invoice();
        $this->paid($order, 30000);
        $before = $this->snapshot($order);
        $fail = true;
        PaymentReceiptNotification::updating(function ($notification) use (&$fail) {
            if ($fail && $notification->status === 'accepted') {
                throw new \RuntimeException('Synthetic persistence interruption.');
            }
        });
        $this->accepted();
        $this->assertSame(1, app(PaymentReceipts::class)->run()['unknown']);
        $fail = false;
        $this->assertSame('claimed', PaymentReceiptNotification::sole()->status);
        app(PaymentReceipts::class)->run();
        Http::assertSentCount(1);
        $this->assertSame($before, $this->snapshot($order));
    }

    public function test_explicit_selection_requires_one_id_and_reason_and_cannot_create_a_retry_of_a_new_email(): void
    {
        $order = $this->invoice();
        $payment = $this->paid($order, 30000);
        $this->artisan('payments:send-receipts', ['--payment' => '1,2', '--reason' => 'Invalid multi-payment request.'])->assertFailed();
        $this->artisan('payments:send-receipts', ['--payment' => (string) $payment->id])->assertFailed();
        $this->assertSame(0, app(PaymentReceipts::class)->run($payment->id, reason: 'There is no rejected email to retry.', retryRejected: true)['accepted']);
        $this->assertDatabaseCount('payment_receipt_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_live_receipt_copy_has_no_test_claim_and_does_not_claim_inbox_delivery(): void
    {
        config(['shop.paymongo.mode' => 'live']);
        $order = $this->invoice();
        $this->paid($order, 30000);
        $this->accepted();
        app(PaymentReceipts::class)->run();
        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString('TEST', $request['subject']);

            return true;
        });
        $this->assertStringContainsString('inbox delivery not confirmed', PaymentReceiptNotification::sole()->label());
    }

    public function test_rejected_test_receipt_keeps_its_test_label_after_provider_mode_changes(): void
    {
        $order = $this->invoice();
        $payment = $this->paid($order, 30000);
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::sequence()->push([], 400)->push(['messageId' => '<mode-retry@brevo.test>'], 201)]);
        $this->assertSame(1, app(PaymentReceipts::class)->run()['failed']);
        $notification = PaymentReceiptNotification::sole();
        $this->assertSame('test', $notification->payment_mode);
        config(['shop.paymongo.mode' => 'live']);
        $this->assertSame(1, app(PaymentReceipts::class)->run($payment->id, reason: 'Corrected sender configuration after a definite rejection.', retryRejected: true)['accepted']);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertStringContainsString('TEST — NO REAL MONEY', $request['subject']);
            $this->assertStringContainsString('TEST PAYMENT ONLY', $request['textContent']);
        }
        $this->assertSame('test', $notification->fresh()->payment_mode);
    }
}
