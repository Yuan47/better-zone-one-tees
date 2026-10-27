<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Lalamove;
use App\Services\PayMongo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerCheckoutAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_checkout_access', 'shop.paymongo.webhook_secret' => 'checkout-access-test',
            'shop.lalamove.mode' => 'sandbox', 'shop.lalamove.key' => 'pk_test_checkout_access', 'shop.lalamove.secret' => 'sk_test_checkout_access',
            'shop.lalamove.address' => '10 Store Street', 'shop.lalamove.lat' => '14.65', 'shop.lalamove.lng' => '121.03', 'shop.lalamove.phone' => '+639123456789']);
        Http::preventStrayRequests();
    }

    private function variant(): Variant
    {
        $product = Product::create(['name' => 'Checkout Access Tee', 'slug' => 'checkout-access-tee', 'category' => '', 'description' => 'Checkout access fixture']);

        return $product->variants()->create(['sku' => 'CHECKOUT-ACCESS-M-WHITE', 'size' => 'M', 'color' => 'White', 'stock' => 20,
            'retail_price' => 16000, 'wholesale_price' => 15000, 'reseller_price' => 14000]);
    }

    private function deliveryDetails(): array
    {
        return ['recipient' => 'Checkout Customer', 'phone' => '09123456789', 'delivery_method' => 'lalamove',
            'street_address' => '12 Example Street', 'city' => 'Quezon City', 'province' => 'Metro Manila', 'postal_code' => '1100',
            'country' => 'Philippines', 'lat' => '14.676', 'lng' => '121.0437'];
    }

    public static function storeRoles(): array
    {
        return [['staff'], ['admin']];
    }

    #[DataProvider('storeRoles')]
    public function test_store_accounts_cannot_start_checkout_quote_or_reservation_even_with_forged_role(string $role): void
    {
        $variant = $this->variant();
        $this->mock(PayMongo::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('ready');
            $mock->shouldNotReceive('create');
        });
        $this->mock(Lalamove::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('ready');
            $mock->shouldNotReceive('quote');
        });
        $message = 'Please sign in with a customer account to check out.';
        $cart = [$variant->id => 2];
        $this->actingAs(User::factory()->create(['role' => $role]))->withSession(['cart' => $cart]);
        $this->get('/checkout')->assertForbidden()->assertSee($message)->assertSessionHas('cart', $cart);
        $payload = $this->deliveryDetails() + ['role' => 'customer'];
        $this->postJson('/checkout/quote', $payload)->assertForbidden()->assertJsonPath('message', $message);
        $this->postJson('/checkout', $payload)->assertForbidden()->assertJsonPath('message', $message)->assertSessionHas('cart', $cart);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    #[DataProvider('storeRoles')]
    public function test_store_accounts_can_still_browse_and_manage_their_cart(string $role): void
    {
        $variant = $this->variant();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get('/')->assertOk()->assertSee('Checkout Access Tee');
        $this->get('/products/'.$variant->product->slug)->assertOk();
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2])->assertRedirect('/cart');
        $this->get('/cart')->assertOk()->assertSee('Checkout Access Tee');
        $this->patch('/cart/'.$variant->id, ['quantity' => 1])->assertRedirect()->assertSessionHas('cart', [$variant->id => 1]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->reserved);
        Http::assertNothingSent();
    }

    public function test_customer_can_open_checkout_get_delivery_quote_and_create_one_pending_order(): void
    {
        $variant = $this->variant();
        $customer = User::factory()->create();
        $quote = ['quotationId' => 'quote_checkout_access', 'priceBreakdown' => ['currency' => 'PHP', 'total' => '100.00'],
            'expiresAt' => now()->addMinutes(5)->toIso8601String(), 'stops' => [['stopId' => 'pickup'], ['stopId' => 'recipient']]];
        Http::fake(['*/v3/quotations' => Http::response(['data' => $quote]),
            'https://api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => ['id' => 'cs_checkout_access',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/checkout-access']]])]);
        $this->actingAs($customer)->withSession(['cart' => [$variant->id => 2]]);
        $this->get('/checkout')->assertOk();
        $this->postJson('/checkout/quote', $this->deliveryDetails())->assertOk()->assertJsonPath('amount', 10000);
        $this->post('/checkout', $this->deliveryDetails())->assertRedirect('https://checkout.paymongo.com/checkout-access')->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertSame(42000, $order->total);
        $this->assertSame(10000, $order->shipping);
        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertSame(2, $variant->fresh()->reserved);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
            && $request['data']['attributes']['reference_number'] === $order->number);
    }
}
