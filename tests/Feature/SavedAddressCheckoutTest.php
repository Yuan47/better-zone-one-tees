<?php

namespace Tests\Feature;

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShoppingCart;
use App\Models\User;
use App\Models\Variant;
use App\Services\CustomerAddresses;
use App\Services\Lalamove;
use App\Services\PayMongo;
use App\Services\SavedCart;
use App\Services\StoreTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class SavedAddressCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function address(User $customer): CustomerAddress
    {
        return app(CustomerAddresses::class)->save($customer, [
            'label' => 'Home', 'recipient' => 'Saved Recipient', 'phone' => '09123456789',
            'street_address' => '12 Test Street', 'city' => 'Imus', 'province' => 'Cavite',
            'postal_code' => '4103', 'country' => 'Philippines', 'lat' => '14.425143', 'lng' => '120.940319',
        ]);
    }

    private function variant(): Variant
    {
        $product = Product::create(['name' => 'Address checkout tee', 'slug' => 'address-checkout-tee',
            'description' => 'Saved address checkout test', 'category' => '']);

        return $product->variants()->create(['sku' => 'ADDRESS-CHECKOUT-TEE', 'size' => 'M', 'color' => 'Red',
            'stock' => 20, 'retail_price' => 15000, 'wholesale_price' => 14000, 'reseller_price' => 13000]);
    }

    private function quote(): array
    {
        return ['quotationId' => 'saved_address_quote', 'expiresAt' => now()->addMinutes(5)->toIso8601String(),
            'stops' => [['stopId' => 'pickup'], ['stopId' => 'recipient']],
            'priceBreakdown' => ['currency' => 'PHP', 'total' => '44.00']];
    }

    private function destination(): string
    {
        return '12 Test Street, Imus, Cavite, 4103, Philippines';
    }

    public function test_saved_address_checkout_uses_current_owned_details_and_full_delivery_total_once(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $address = $this->address($customer);
        $variant = $this->variant();
        $quote = $this->quote();
        $key = (string) Str::uuid();
        $this->mock(Lalamove::class, function (MockInterface $mock) use ($quote) {
            $mock->shouldReceive('quote')->once()->with($this->destination(), '14.425143', '120.940319')->andReturn($quote);
        });
        $this->mock(PayMongo::class, function (MockInterface $mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('create')->once()->andReturnUsing(function (Order $order, $attempt) {
                $this->assertSame($this->destination(), $order->address);
                $this->assertSame('Saved Recipient', $order->recipient);
                $this->assertSame('09123456789', $order->phone);
                $this->assertSame(4400, $order->shipping);
                $this->assertSame(34400, $order->total);
                $this->assertSame($order->total, $attempt->amount);

                return ['checkout_session_id' => 'cs_saved_address', 'checkout_url' => 'https://checkout.paymongo.com/saved-address'];
            });
        });
        $this->actingAs($customer)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2]);
        $this->withSession(['checkout_key' => $key, 'checkout_keys' => [$key]])
            ->postJson('/checkout/quote', ['address_id' => $address->id, 'pin_confirmed' => '1',
                'street_address' => 'Forged location', 'lat' => '0', 'lng' => '0'])
            ->assertOk()->assertJsonPath('amount', 4400);
        $payload = ['checkout_key' => $key, 'delivery_method' => 'lalamove', 'address_id' => $address->id,
            'pin_confirmed' => '1', 'terms' => '1', 'recipient' => 'Injected recipient', 'phone' => '000'];
        $this->post('/checkout', $payload)->assertRedirect('https://checkout.paymongo.com/saved-address');
        $order = Order::sole();
        $this->assertSame('full', $order->payment_plan);
        $this->assertSame(StoreTerms::VERSION, $order->terms_version);
        $this->assertNotNull($order->terms_accepted_at);
        $this->assertSame([], ShoppingCart::where('user_id', $customer->id)->sole()->items);
        $this->post('/checkout', $payload)->assertRedirect('/orders/'.$order->id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(2, $variant->fresh()->reserved);
        $this->assertSame(20, $variant->fresh()->stock);
        $address->update(['street_address' => 'New address after ordering']);
        $this->assertSame('12 Test Street', $order->fresh()->street_address);
    }

    public function test_saved_delivery_address_still_requires_explicit_pin_confirmation(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $address = $this->address($customer);
        $variant = $this->variant();
        $this->mock(Lalamove::class, fn (MockInterface $mock) => $mock->shouldNotReceive('quote'));
        $this->mock(PayMongo::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));
        $this->actingAs($customer)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 1]);
        $this->postJson('/checkout/quote', ['address_id' => $address->id])
            ->assertUnprocessable()->assertJsonValidationErrors('pin_confirmed');
        $this->post('/checkout', ['delivery_method' => 'lalamove', 'address_id' => $address->id, 'terms' => '1'])
            ->assertSessionHasErrors('pin_confirmed');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        $this->assertSame(1, ShoppingCart::where('user_id', $customer->id)->sole()->items[$variant->id]);
    }

    public function test_foreign_saved_address_cannot_be_submitted_for_checkout(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $address = $this->address($owner);
        $other = User::factory()->create(['role' => 'customer']);
        $variant = $this->variant();
        $this->mock(PayMongo::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));
        $this->actingAs($other)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 1]);
        $this->post('/checkout', ['delivery_method' => 'lalamove', 'address_id' => $address->id,
            'pin_confirmed' => '1', 'terms' => '1'])->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
    }

    public function test_changing_a_saved_address_invalidates_its_previously_obtained_fee(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $address = $this->address($customer);
        $variant = $this->variant();
        $quote = $this->quote();
        $this->mock(Lalamove::class, fn (MockInterface $mock) => $mock->shouldReceive('quote')->once()->andReturn($quote));
        $this->mock(PayMongo::class, function (MockInterface $mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldNotReceive('create');
        });
        $this->actingAs($customer)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 1]);
        $this->postJson('/checkout/quote', ['address_id' => $address->id, 'pin_confirmed' => '1'])->assertOk();
        $address->update(['street_address' => 'Changed saved destination']);
        $this->post('/checkout', ['delivery_method' => 'lalamove', 'address_id' => $address->id,
            'pin_confirmed' => '1', 'terms' => '1'])->assertSessionHasErrors('delivery');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
    }

    public function test_saved_cart_failure_rolls_back_order_and_stock_reservation_together(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $variant = $this->variant();
        $this->actingAs($customer)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2]);
        $this->partialMock(SavedCart::class, fn (MockInterface $mock) => $mock->shouldReceive('consume')->once()->andThrow(new \RuntimeException('Saved cart unavailable')));
        $this->mock(PayMongo::class, function (MockInterface $mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldNotReceive('create');
        });
        $this->post('/checkout', ['delivery_method' => 'pickup', 'recipient' => 'Test Customer',
            'phone' => '09123456789', 'terms' => '1'])->assertServerError();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(0, $variant->fresh()->reserved);
        $this->assertSame(2, ShoppingCart::where('user_id', $customer->id)->sole()->items[$variant->id]);
    }
}
