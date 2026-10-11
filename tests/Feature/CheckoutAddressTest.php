<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Lalamove;
use App\Services\PayMongo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CheckoutAddressTest extends TestCase
{
    use RefreshDatabase;

    private function variant(): Variant
    {
        $product = Product::create(['name' => 'Address Test Tee', 'slug' => 'address-test-tee', 'category' => '', 'description' => 'Checkout address test']);

        return $product->variants()->create(['sku' => 'ADDRESS-TEE-M-RED', 'size' => 'M', 'color' => 'Red', 'color_group' => 'COLORED',
            'stock' => 20, 'retail_price' => 16000, 'wholesale_price' => 15000, 'reseller_price' => 14000]);
    }

    private function address(): array
    {
        return ['street_address' => '12 Example Street, Barangay Uno', 'city' => 'Quezon City', 'province' => 'Metro Manila',
            'postal_code' => '1100', 'country' => 'Philippines', 'lat' => '14.676', 'lng' => '121.0437'];
    }

    private function canonicalAddress(): string
    {
        return '12 Example Street, Barangay Uno, Quezon City, Metro Manila, 1100, Philippines';
    }

    private function details(string $method = 'pickup'): array
    {
        return ['recipient' => 'Sample Recipient', 'phone' => '09123456789', 'delivery_method' => $method];
    }

    private function payment(?callable $inspect = null): void
    {
        $this->mock(PayMongo::class, function (MockInterface $mock) use ($inspect) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('create')->once()->andReturnUsing(function (Order $order) use ($inspect) {
                if ($inspect) {
                    $inspect($order);
                }

                return ['checkout_session_id' => 'cs_address_test', 'checkout_url' => 'https://checkout.paymongo.com/address-test'];
            });
        });
    }

    private function quote(): array
    {
        return ['quotationId' => 'quote_address_test', 'priceBreakdown' => ['currency' => 'PHP', 'total' => '125.50'],
            'expiresAt' => now()->addMinutes(5)->toIso8601String()];
    }

    public function test_saved_customer_address_prefills_checkout(): void
    {
        $variant = $this->variant();
        $user = User::factory()->create($this->address() + ['name' => 'Saved Customer', 'phone' => '09123456789']);
        $this->mock(PayMongo::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')->andReturn(true));
        $this->mock(Lalamove::class, fn (MockInterface $mock) => $mock->shouldReceive('ready')->andReturn(true));
        $response = $this->actingAs($user)->withSession(['cart' => [$variant->id => 1]])->get('/checkout')->assertOk();
        foreach (['street_address', 'city', 'province', 'postal_code', 'country'] as $field) {
            $response->assertSee('value="'.$this->address()[$field].'"', false);
            $response->assertSee('name="'.$field.'"', false);
        }
        $response->assertSee('value="09123456789"', false)->assertSee('value="Saved Customer"', false)->assertSee('My Account');
        $response->assertDontSee('Address / pickup note')->assertDontSee('name="address"', false);
    }

    public function test_structured_address_is_snapshotted_and_provider_address_ignores_client_injection(): void
    {
        $variant = $this->variant();
        $user = User::factory()->create();
        $this->payment(function (Order $order) {
            $this->assertSame($this->canonicalAddress(), $order->address);
            $this->assertSame('Quezon City', $order->city);
        });
        $this->actingAs($user)->withSession(['cart' => [$variant->id => 2]])
            ->post('/checkout', $this->details() + $this->address() + ['address' => 'Injected untrusted destination'])
            ->assertRedirect('https://checkout.paymongo.com/address-test');
        $order = Order::sole();
        foreach ($this->address() as $field => $value) {
            $this->assertSame($value, $order->getAttribute($field));
        }
        $this->assertSame($this->canonicalAddress(), $order->address);
        $this->assertSame(0, $order->shipping);
        $user->update(['street_address' => 'New saved address', 'city' => 'New City']);
        $this->assertSame('12 Example Street, Barangay Uno', $order->fresh()->street_address);
        $this->assertSame('Quezon City', $order->fresh()->city);
    }

    public function test_pickup_requires_neither_delivery_address_nor_note(): void
    {
        $variant = $this->variant();
        $this->payment(function (Order $order) {
            $this->assertSame('Store pickup', $order->address);
        });
        $this->actingAs(User::factory()->create())->withSession(['cart' => [$variant->id => 1]])
            ->post('/checkout', $this->details())->assertRedirect('https://checkout.paymongo.com/address-test');
        $order = Order::sole();
        $this->assertSame('pickup', $order->delivery_method);
        $this->assertNull($order->street_address);
        $this->assertNull($order->city);
        $this->assertSame('Philippines', $order->country);
        $this->assertSame(0, $order->shipping);
    }

    public function test_delivery_quote_requires_complete_structured_address_and_coordinates(): void
    {
        $this->mock(Lalamove::class, fn (MockInterface $mock) => $mock->shouldNotReceive('quote'));
        $this->actingAs(User::factory()->create())->postJson('/checkout/quote', ['address' => 'Legacy free text only'])
            ->assertUnprocessable()->assertJsonValidationErrors(['street_address', 'city', 'province', 'postal_code', 'country']);
        $withoutCoordinates = $this->address();
        unset($withoutCoordinates['lat'], $withoutCoordinates['lng']);
        $this->postJson('/checkout/quote', $withoutCoordinates)->assertUnprocessable()->assertJsonValidationErrors(['lat', 'lng']);
        $incomplete = $this->address();
        unset($incomplete['city']);
        $this->postJson('/checkout/quote', $incomplete)->assertUnprocessable()->assertJsonValidationErrors('city');
        $this->postJson('/checkout/quote', array_replace($this->address(), ['lat' => 91]))->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->postJson('/checkout/quote', array_replace($this->address(), ['country' => 'Singapore']))->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quote_uses_canonical_address_and_changed_address_is_rejected_before_payment(): void
    {
        $variant = $this->variant();
        $quote = $this->quote();
        $this->mock(Lalamove::class, function (MockInterface $mock) use ($quote) {
            $mock->shouldReceive('quote')->once()->with($this->canonicalAddress(), '14.676', '121.0437')->andReturn($quote);
        });
        $this->payment(function (Order $order) use ($quote) {
            $this->assertSame($this->canonicalAddress(), $order->address);
            $this->assertSame(12550, $order->shipping);
            $this->assertSame($quote, $order->delivery_quote);
            $this->assertSame($order->subtotal + 12550, $order->total);
        });
        $this->actingAs(User::factory()->create())->withSession(['cart' => [$variant->id => 1]])
            ->postJson('/checkout/quote', $this->address() + ['address' => 'Injected destination'])
            ->assertOk()->assertJsonPath('amount', 12550)->assertSessionHas('delivery_quote.address', $this->canonicalAddress());
        $changed = array_replace($this->address(), ['street_address' => '99 Different Street']);
        $this->post('/checkout', $this->details('lalamove') + $changed)->assertSessionHasErrors('delivery');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        $this->post('/checkout', $this->details('lalamove') + $this->address() + ['address' => 'Another injected destination'])
            ->assertRedirect('https://checkout.paymongo.com/address-test');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($this->canonicalAddress(), Order::sole()->address);
    }

    public function test_changed_coordinates_require_a_new_quote(): void
    {
        $variant = $this->variant();
        $quote = $this->quote();
        $this->mock(Lalamove::class, function (MockInterface $mock) use ($quote) {
            $mock->shouldReceive('quote')->once()->with($this->canonicalAddress(), '14.676', '121.0437')->andReturn($quote);
        });
        $this->mock(PayMongo::class, function (MockInterface $mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldNotReceive('create');
        });
        $this->actingAs(User::factory()->create())->withSession(['cart' => [$variant->id => 1]])->postJson('/checkout/quote', $this->address())->assertOk();
        $changed = array_replace($this->address(), ['lat' => '14.7']);
        $this->post('/checkout', $this->details('lalamove') + $changed)->assertSessionHasErrors('delivery');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
    }
}
