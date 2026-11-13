<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\InvoicePayments;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test']);
        Http::preventStrayRequests();
    }

    private function invoice(string $channel = 'online', bool $guest = false): Order
    {
        $buyer = $guest ? null : User::factory()->create(['role' => 'customer']);
        $product = Product::create(['name' => 'Progress tee', 'slug' => 'progress-'.Str::uuid(), 'category' => '', 'description' => 'Isolated checkout fixture']);
        $variant = $product->variants()->create(['sku' => (string) Str::uuid(), 'size' => 'M', 'color' => 'White',
            'stock' => 10, 'retail_price' => 20000, 'wholesale_price' => 18000, 'reseller_price' => 16000]);

        return app(OrderFlow::class)->reserve([$variant->id => 1], ['recipient' => 'Progress customer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0, 'sales_channel' => $channel], $buyer?->id);
    }

    private function attempt(Order $order, string $status = 'pending', ?int $amount = null): PaymentAttempt
    {
        return $order->payments()->create(['reference' => (string) Str::uuid(), 'method' => 'paymongo',
            'status' => $status, 'amount' => $amount ?? $order->total]);
    }

    private function receive(PaymentAttempt $attempt): void
    {
        app(InvoicePayments::class)->receiveProvider($attempt, ['id' => 'pay_progress_'.$attempt->id, 'attributes' => [
            'status' => 'paid', 'currency' => 'PHP', 'amount' => $attempt->amount, 'livemode' => false, 'paid_at' => now()->timestamp,
        ]]);
    }

    private function assertStage(TestResponse $response, int $stage, string $label): void
    {
        $response->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $current = $xpath->query('//ol[@id="checkout-progress"]/li[@aria-current="step"]');
        $this->assertSame(1, $current->length);
        $this->assertSame($label, trim($current->item(0)->textContent));
        $this->assertSame((string) $stage, $current->item(0)->getAttribute('data-checkout-step'));
        $this->assertSame($stage - 1, $xpath->query('//ol[@id="checkout-progress"]/li[@data-complete]')->length);
    }

    public function test_checkout_renders_the_delivery_stage_before_customer_input(): void
    {
        $order = $this->invoice();
        $variant = Variant::sole();
        $this->actingAs($order->user)->withSession(['cart' => [$variant->id => 1]]);
        $this->assertStage($this->get('/checkout'), 1, 'Delivery details');
        Http::assertNothingSent();
    }

    public static function unconfirmedPaymentStates(): array
    {
        return [['pending'], ['creating'], ['unknown'], ['failed']];
    }

    #[DataProvider('unconfirmedPaymentStates')]
    public function test_provider_return_parameters_cannot_complete_unconfirmed_payment_progress(string $state): void
    {
        $order = $this->invoice();
        $this->attempt($order, $state);
        $this->actingAs($order->user);
        $this->assertStage($this->get('/orders/'.$order->id.'?success=true&payment_status=paid'), 3, 'Pay securely');
        $this->assertSame('pending', $order->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_provider_confirmed_full_payment_advances_the_saved_order_to_confirmation(): void
    {
        $order = $this->invoice();
        $attempt = $this->attempt($order);
        $this->actingAs($order->user);
        $this->assertStage($this->get('/orders/'.$order->id), 3, 'Pay securely');
        $this->receive($attempt);
        $this->assertStage($this->get('/orders/'.$order->id), 4, 'Order confirmation');
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(0, $order->fresh()->outstandingBalance());
        Http::assertNothingSent();
    }

    public function test_historical_deposit_keeps_payment_in_progress_until_the_full_balance_is_received(): void
    {
        $order = $this->invoice();
        $order->update(['payment_plan' => 'partial', 'due_at' => now()->addDays(14), 'original_due_at' => now()->addDays(14)]);
        $deadline = $order->getRawOriginal('due_at');
        $this->receive($this->attempt($order, amount: 10000));
        $this->actingAs($order->user);
        $this->assertStage($this->get('/orders/'.$order->id), 3, 'Pay securely');
        $this->receive($this->attempt($order->fresh(), amount: 10000));
        $this->assertStage($this->get('/orders/'.$order->id), 4, 'Order confirmation');
        $this->assertSame($deadline, $order->fresh()->getRawOriginal('due_at'));
        Http::assertNothingSent();
    }

    public function test_closed_invoices_and_walk_in_sales_do_not_offer_an_online_checkout_progress(): void
    {
        $order = $this->invoice('walk_in');
        $this->actingAs($order->user)->get('/orders/'.$order->id)->assertOk()->assertDontSee('id="checkout-progress"', false);
        $online = $this->invoice();
        app(OrderFlow::class)->cancel($online);
        $this->actingAs($online->user)->get('/orders/'.$online->id)->assertOk()->assertDontSee('id="checkout-progress"', false);
        Http::assertNothingSent();
    }

    public function test_private_guest_online_invoice_advances_only_after_provider_confirmation(): void
    {
        $order = $this->invoice('walk_in', guest: true);
        $attempt = $this->attempt($order);
        $url = URL::temporarySignedRoute('guest.orders.show', now()->addHour(), ['order' => $order->id]);
        $this->assertStage($this->get($url), 3, 'Pay securely');
        $this->receive($attempt);
        $this->assertStage($this->get($url), 4, 'Order confirmation');
        $this->assertTrue($order->fresh()->deliveryConfirmed());
        Http::assertNothingSent();
    }

    public function test_guest_cash_and_closed_invoices_do_not_offer_online_checkout_progress(): void
    {
        $order = $this->invoice('walk_in', guest: true);
        app(InvoicePayments::class)->recordCash($order, $order->total, User::factory()->create(['role' => 'staff']),
            'Full cash counted and received for the guest.', (string) Str::uuid());
        $url = URL::temporarySignedRoute('guest.orders.show', now()->addHour(), ['order' => $order->id]);
        $this->get($url)->assertOk()->assertDontSee('id="checkout-progress"', false);
        $closed = $this->invoice('walk_in', guest: true);
        $this->attempt($closed, 'failed');
        app(OrderFlow::class)->cancel($closed);
        $url = URL::temporarySignedRoute('guest.orders.show', now()->addHour(), ['order' => $closed->id]);
        $this->get($url)->assertOk()->assertDontSee('id="checkout-progress"', false);
        Http::assertNothingSent();
    }
}
