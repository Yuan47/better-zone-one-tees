<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyPaymentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_legacy_boundary',
            'shop.paymongo.webhook_secret' => 'legacy-boundary-secret']);
        Http::preventStrayRequests();
    }

    private function order(): Order
    {
        $product = Product::create(['name' => 'Legacy payment tee', 'slug' => 'legacy-boundary-'.Str::random(8), 'description' => 'Test fixture', 'category' => '']);
        $variant = $product->variants()->create(['sku' => (string) Str::uuid(), 'size' => 'M', 'color' => 'White',
            'stock' => 10, 'retail_price' => 20000, 'wholesale_price' => 18000, 'reseller_price' => 16000]);
        $order = app(OrderFlow::class)->reserve([$variant->id => 2], ['recipient' => 'Legacy buyer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0], User::factory()->create(['role' => 'customer'])->id);
        $order->update(['checkout_session_id' => 'cs_legacy_boundary_'.$order->id]);

        return $order->fresh();
    }

    private function payment(Order $order): array
    {
        return ['id' => 'pay_legacy_boundary', 'attributes' => ['status' => 'paid', 'currency' => 'PHP',
            'amount' => $order->total, 'livemode' => false, 'paid_at' => now()->timestamp]];
    }

    private function assertUnchanged(Order $order): void
    {
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->payment_id);
        $this->assertFalse($order->fresh()->inventory_committed);
        $this->assertSame(10, Variant::sole()->stock);
        $this->assertSame(2, Variant::sole()->reserved);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public static function ambiguousWebhookPayments(): array
    {
        return [['multiple'], ['mode'], ['identifier']];
    }

    #[DataProvider('ambiguousWebhookPayments')]
    public function test_legacy_signed_webhook_requires_one_valid_payment_in_the_configured_mode(string $case): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        if ($case === 'mode') {
            $payment['attributes']['livemode'] = true;
        } elseif ($case === 'identifier') {
            $payment['id'] = 'invalid-provider-payment';
        }
        $payments = [$payment];
        if ($case === 'multiple') {
            $payments[] = array_replace($payment, ['id' => 'pay_second_legacy_payment']);
        }
        $body = json_encode(['data' => ['id' => 'evt_legacy_boundary', 'attributes' => [
            'type' => 'checkout_session.payment.paid', 'livemode' => false,
            'data' => ['id' => $order->checkout_session_id, 'attributes' => ['reference_number' => $order->number, 'payments' => $payments]],
        ]]]);
        $time = (string) time();
        $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => 't='.$time.',te='.hash_hmac('sha256', $time.'.'.$body, 'legacy-boundary-secret')], $body)
            ->assertConflict()->assertJsonPath('outcome', 'mismatch');
        $this->assertUnchanged($order);
    }

    public function test_legacy_provider_reconciliation_keeps_multiple_paid_transactions_for_owner_review(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/'.$order->checkout_session_id => Http::response(['data' => [
            'id' => $order->checkout_session_id, 'attributes' => ['reference_number' => $order->number, 'livemode' => false,
                'line_items' => [['amount' => $order->total, 'quantity' => 1, 'currency' => 'PHP']],
                'payments' => [$payment, array_replace($payment, ['id' => 'pay_second_legacy_payment'])]],
        ]])]);
        $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/payment/check')
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertUnchanged($order);
        Http::assertSentCount(1);
    }
}
