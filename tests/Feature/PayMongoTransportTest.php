<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Models\Variant;
use App\Services\InvoicePayments;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use App\Services\RefundVerification;
use App\Services\ReturnInspections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayMongoTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_transport',
            'shop.paymongo.webhook_secret' => 'transport-webhook-secret']);
        Http::preventStrayRequests();
    }

    private function invoice(bool $partial = false): Order
    {
        $buyer = User::factory()->create(['role' => 'customer']);
        $product = Product::create(['name' => 'Transport test tee', 'slug' => 'transport-'.Str::uuid(), 'category' => '', 'description' => 'Test fixture']);
        $variant = $product->variants()->create(['sku' => 'TRANSPORT-'.Str::uuid(), 'size' => 'M', 'color' => 'White', 'stock' => 20,
            'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);

        return app(OrderFlow::class)->reserve([$variant->id => $partial ? 12 : 1], ['recipient' => 'Transport test buyer',
            'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0,
            'payment_plan' => $partial ? 'partial' : 'full', 'due_at' => $partial ? now()->addDays(14) : null], $buyer->id)->fresh();
    }

    private function attempt(Order $order, ?int $amount = null): PaymentAttempt
    {
        $id = 'cs_transport_'.Str::random(16);
        $attempt = $order->payments()->create(['reference' => (string) Str::uuid(), 'method' => 'paymongo', 'status' => 'pending',
            'amount' => $amount ?? $order->total, 'checkout_session_id' => $id, 'checkout_url' => 'https://checkout.paymongo.com/'.$id]);
        $order->update(['checkout_session_id' => $id, 'checkout_url' => $attempt->checkout_url]);

        return $attempt;
    }

    private function payment(PaymentAttempt $attempt): array
    {
        return ['id' => 'pay_transport_'.$attempt->id, 'attributes' => ['status' => 'paid', 'currency' => 'PHP',
            'amount' => $attempt->amount, 'livemode' => false, 'paid_at' => now()->timestamp]];
    }

    private function checkoutData(Order $order, PaymentAttempt $attempt): array
    {
        return ['data' => ['id' => $attempt->checkout_session_id, 'attributes' => ['reference_number' => $order->number,
            'livemode' => false, 'status' => 'active', 'checkout_url' => $attempt->checkout_url,
            'line_items' => [['currency' => 'PHP', 'amount' => $attempt->amount, 'quantity' => 1]],
            'payments' => [$this->payment($attempt)], 'payment_intent' => ['attributes' => ['status' => 'succeeded']],
        ]]];
    }

    private function inspectedRefund(): array
    {
        $order = $this->invoice(true);
        $deposit = $this->attempt($order, 30000);
        app(InvoicePayments::class)->receiveProvider($deposit, $this->payment($deposit));
        $balance = $this->attempt($order->fresh(), 78000);
        app(InvoicePayments::class)->receiveProvider($balance, $this->payment($balance));
        $order->refresh()->update(['status' => 'completed', 'customer_confirmed_at' => now()]);
        $order->review()->create(['user_id' => $order->user_id, 'rating' => 4, 'comment' => 'Delivered test clothing checked by its customer.']);
        $owner = User::factory()->create(['role' => 'admin']);
        $refund = $order->refunds()->create(['requested_by' => $order->user_id, 'requested_amount' => $order->total,
            'reason' => 'The inspected clothing was returned for this test.', 'status' => 'approved', 'approved_amount' => 10000,
            'payment_attempt_id' => $deposit->id, 'reviewed_by' => $owner->id, 'reviewed_at' => now(),
            'decision_note' => 'Owner approved the inspected return against the original deposit receipt.']);
        app(ReturnInspections::class)->record($refund, $owner, [
            'items' => $order->items->map(fn ($item) => ['order_item_id' => $item->id, 'quantity' => $item->quantity, 'condition' => 'good'])->all(),
            'include_delivery' => true, 'inspection_note' => 'Owner physically inspected the returned test clothing.',
            'photo_exception_reason' => 'Supervised automated fixture does not contain an inspection photograph.',
        ]);

        return [$order->fresh(), $refund->fresh(), $deposit->fresh()];
    }

    private function refundBody(PaymentAttempt $source): array
    {
        return ['data' => ['id' => 'ref_transport', 'attributes' => ['payment_id' => $source->payment_id,
            'currency' => 'PHP', 'livemode' => false, 'amount' => 10000, 'status' => 'succeeded', 'updated_at' => time()]]];
    }

    private function commerce(Order $order): array
    {
        return ['invoice' => $order->fresh()->getAttributes(), 'variants' => Variant::orderBy('id')->get()->map->getAttributes()->all(),
            'receipts' => $order->payments()->orderBy('id')->get()->map->getAttributes()->all(),
            'refunds' => $order->refunds()->orderBy('id')->get()->map->getAttributes()->all()];
    }

    private function runCatching(callable $operation): ?\Throwable
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }

    public static function redirects(): iterable
    {
        yield '302' => [302];
        yield '307' => [307];
    }

    public static function nonSuccessStatuses(): iterable
    {
        foreach ([100, 199, 300, 301, 302, 303, 304, 307, 308, 399] as $status) {
            yield (string) $status => [$status, false];
        }
        yield '302 final settlement' => [302, true];
        yield '307 final settlement' => [307, true];
    }

    #[DataProvider('redirects')]
    public function test_redirected_checkout_creation_keeps_an_uncertain_attempt_without_accepting_a_session(int $status): void
    {
        $order = $this->invoice(true);
        $before = $this->commerce($order);
        $options = [];
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => function ($request, $requestOptions) use ($status, &$options) {
            $options = $requestOptions;

            return Http::response(['data' => ['id' => 'cs_forged_redirect', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/forged']]], $status,
                ['Location' => 'https://other.example.test/collect-credentials']);
        }]);
        $error = $this->runCatching(fn () => app(InvoicePayments::class)->start($order, 60000, $order->user, (string) Str::uuid()));
        $this->assertSame($before['invoice'], $order->fresh()->getAttributes());
        $this->assertSame($before['variants'], Variant::orderBy('id')->get()->map->getAttributes()->all());
        $attempt = $order->payments()->sole();
        $this->assertSame('unknown', $attempt->status);
        $this->assertSame(60000, $attempt->amount);
        $this->assertNull($attempt->checkout_session_id);
        $this->assertNull($attempt->payment_id);
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertFalse($options['allow_redirects'] ?? true);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.example.test'));
    }

    #[DataProvider('nonSuccessStatuses')]
    public function test_non_2xx_paid_lookup_cannot_confirm_an_installment_or_deduct_stock(int $status, bool $full): void
    {
        $order = $this->invoice(true);
        $attempt = $this->attempt($order, $full ? $order->total : 30000);
        $before = $this->commerce($order);
        $options = [];
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/*' => function ($request, $requestOptions) use ($order, $attempt, $status, &$options) {
            $options = $requestOptions;

            return Http::response($this->checkoutData($order, $attempt), $status, ['Location' => 'https://other.example.test/paid']);
        }]);
        $error = $this->runCatching(fn () => app(InvoicePayments::class)->check($order));
        $this->assertSame($before, $this->commerce($order));
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertFalse($options['allow_redirects'] ?? true);
        Http::assertSentCount(1);
    }

    #[DataProvider('redirects')]
    public function test_redirected_refund_cannot_confirm_money_returned_or_consume_refundable_caps(int $status): void
    {
        [$order, $refund, $source] = $this->inspectedRefund();
        $before = $this->commerce($order);
        $caps = [Refund::remainingForOrder($order), Refund::remainingForPayment($order, $source), $refund->returnInspection->remaining()];
        $options = [];
        Http::fake(['https://api.paymongo.com/refunds/ref_transport' => function ($request, $requestOptions) use ($source, $status, &$options) {
            $options = $requestOptions;

            return Http::response($this->refundBody($source), $status, ['Location' => 'https://other.example.test/refund']);
        }]);
        $error = $this->runCatching(fn () => app(RefundVerification::class)->check($refund, 'ref_transport', $source->id));
        $this->assertSame($before, $this->commerce($order));
        $this->assertSame($caps, [Refund::remainingForOrder($order), Refund::remainingForPayment($order, $source), $refund->returnInspection->remaining()]);
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertFalse($options['allow_redirects'] ?? true);
        Http::assertSentCount(1);
    }

    #[DataProvider('redirects')]
    public function test_expiry_refuses_redirects_without_changing_invoice_state(int $status): void
    {
        $order = $this->invoice();
        $attempt = $this->attempt($order);
        $before = $this->commerce($order);
        $options = [];
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/*/expire' => function ($request, $requestOptions) use ($status, &$options) {
            $options = $requestOptions;

            return Http::response(['data' => ['attributes' => ['status' => 'expired']]], $status, ['Location' => 'https://other.example.test/expired']);
        }]);
        $error = $this->runCatching(fn () => app(PayMongo::class)->expire($attempt->checkout_session_id));
        $this->assertSame($before, $this->commerce($order));
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertFalse($options['allow_redirects'] ?? true);
        Http::assertSentCount(1);
    }

    public static function operations(): iterable
    {
        yield 'create' => ['create', 201, 422];
        yield 'retrieve' => ['retrieve', 200, 401];
        yield 'expire' => ['expire', 204, 404];
        yield 'refund' => ['refund', 200, 404];
    }

    private function operation(string $operation, Order $order, ?Refund $refund, ?PaymentAttempt $source): mixed
    {
        return match ($operation) {
            'create' => app(PayMongo::class)->create($order),
            'retrieve' => app(PayMongo::class)->retrieve('cs_transport_lookup'),
            'expire' => app(PayMongo::class)->expire('cs_transport_lookup'),
            'refund' => app(RefundVerification::class)->check($refund, 'ref_transport', $source->id),
        };
    }

    #[DataProvider('operations')]
    public function test_successful_transport_disables_redirects_and_keeps_supported_2xx_responses(string $operation, int $success, int $failure): void
    {
        [$order, $refund, $source] = $operation === 'refund' ? $this->inspectedRefund() : [$this->invoice(), null, null];
        $options = [];
        $body = $source ? $this->refundBody($source) : ['data' => ['id' => 'cs_transport_lookup', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/lookup']]];
        Http::fake(['https://api.paymongo.com/*' => function ($request, $requestOptions) use ($body, $success, &$options) {
            $options = $requestOptions;

            return Http::response($success === 204 ? '' : $body, $success);
        }]);
        $result = $this->operation($operation, $order, $refund, $source);
        $this->assertFalse($options['allow_redirects'] ?? true);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertGreaterThan(0, $options['timeout']);
        $this->assertLessThanOrEqual(25, $options['timeout']);
        if ($operation === 'refund') {
            $this->assertSame('succeeded', $result->status);
        } elseif ($operation === 'expire') {
            $this->assertNull($result);
        } else {
            $this->assertSame('cs_transport_lookup', $result[$operation === 'create' ? 'checkout_session_id' : 'id']);
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('operations')]
    public function test_client_errors_preserve_the_existing_request_exception_contract(string $operation, int $success, int $failure): void
    {
        [$order, $refund, $source] = $operation === 'refund' ? $this->inspectedRefund() : [$this->invoice(), null, null];
        $before = $this->commerce($order);
        Http::fake(['https://api.paymongo.com/*' => Http::response(['errors' => [['code' => 'test_rejection']]], $failure)]);
        $error = $this->runCatching(fn () => $this->operation($operation, $order, $refund, $source));
        $this->assertInstanceOf(RequestException::class, $error);
        $this->assertSame($failure, $error->response->status());
        $this->assertSame($before, $this->commerce($order));
        Http::assertSentCount(1);
    }
}
