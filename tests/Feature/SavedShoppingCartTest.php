<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ShoppingCart;
use App\Models\User;
use App\Models\Variant;
use App\Services\PayMongo;
use App\Services\SavedCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SavedShoppingCartTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer', 'password' => 'CartPassword!2026']);
    }

    private function variant(string $suffix = 'one'): Variant
    {
        $product = Product::create(['name' => 'Saved Cart Tee '.$suffix, 'slug' => 'saved-cart-tee-'.$suffix,
            'description' => 'Saved cart test product', 'category' => '']);

        return $product->variants()->create(['sku' => 'SAVED-CART-'.$suffix, 'size' => 'M', 'color' => 'White',
            'stock' => 500, 'retail_price' => 15000, 'wholesale_price' => 14000, 'reseller_price' => 13000]);
    }

    public function test_signed_in_cart_survives_logout_and_a_fresh_login(): void
    {
        $customer = $this->customer();
        $variant = $this->variant();
        $this->actingAs($customer)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 3])->assertRedirect('/cart');
        $this->assertSame(3, ShoppingCart::where('user_id', $customer->id)->sole()->items[$variant->id]);
        $this->post('/logout')->assertRedirect('/');
        $this->get('/cart')->assertOk()->assertSessionHas('cart', []);
        $this->flushSession();
        $this->post('/login', ['email' => $customer->email, 'password' => 'CartPassword!2026'])->assertRedirect('/');
        $this->get('/cart')->assertOk()->assertSessionHas('cart.'.$variant->id, 3);
        $this->assertSame(500, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->reserved);
    }

    public function test_switching_accounts_does_not_expose_or_import_the_other_cart(): void
    {
        $first = $this->customer();
        $second = $this->customer();
        $variant = $this->variant();
        $this->actingAs($first)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 4]);
        $this->post('/logout');
        $this->post('/login', ['email' => $second->email, 'password' => 'CartPassword!2026']);
        $this->get('/cart')->assertSessionHas('cart', []);
        $this->assertSame([], ShoppingCart::where('user_id', $second->id)->sole()->items);
        $this->assertSame(4, ShoppingCart::where('user_id', $first->id)->sole()->items[$variant->id]);
    }

    public function test_guest_cart_restores_after_session_loss_using_an_opaque_cookie(): void
    {
        $variant = $this->variant();
        $response = $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2])->assertRedirect('/cart');
        $response->assertCookie(SavedCart::COOKIE);
        $token = $response->getCookie(SavedCart::COOKIE)->getValue();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        $this->assertDatabaseHas('shopping_carts', ['user_id' => null, 'guest_token_hash' => hash('sha256', $token)]);
        $this->flushSession();
        $this->withCookie(SavedCart::COOKIE, $token)->get('/cart')->assertOk()->assertSessionHas('cart.'.$variant->id, 2);
    }

    public function test_guest_and_saved_cart_merge_once_without_adding_the_same_quantities_again(): void
    {
        $customer = $this->customer();
        $variant = $this->variant();
        ShoppingCart::create(['user_id' => $customer->id, 'items' => [$variant->id => 3]]);
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2]);
        $this->post('/login', ['email' => $customer->email, 'password' => 'CartPassword!2026']);
        $this->get('/cart')->assertSessionHas('cart.'.$variant->id, 3);
        $this->get('/cart')->assertSessionHas('cart.'.$variant->id, 3);
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 1]);
        $this->assertSame(4, ShoppingCart::where('user_id', $customer->id)->sole()->items[$variant->id]);
    }

    public function test_cart_quantities_can_be_changed_and_removed_without_reserving_inventory(): void
    {
        $customer = $this->customer();
        $variant = $this->variant();
        $this->actingAs($customer)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2]);
        $this->patch('/cart/'.$variant->id, ['quantity' => 5])->assertRedirect();
        $this->assertSame(5, ShoppingCart::where('user_id', $customer->id)->sole()->items[$variant->id]);
        $this->patch('/cart/'.$variant->id, ['quantity' => 0])->assertRedirect();
        $this->assertSame([], ShoppingCart::where('user_id', $customer->id)->sole()->items);
        $this->assertSame(0, $variant->fresh()->reserved);
    }

    public static function cartOwners(): array
    {
        return [['customer'], ['guest']];
    }

    #[DataProvider('cartOwners')]
    public function test_adding_beyond_the_saved_cart_limit_keeps_the_existing_quantity(string $owner): void
    {
        if ($owner === 'customer') {
            $this->actingAs($this->customer());
        }
        $variant = $this->variant();
        $variant->update(['stock' => 20000]);
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 9999])
            ->assertRedirect('/cart')->assertSessionHasNoErrors();
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertRedirect('/cart')->assertSessionHasNoErrors();

        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertRedirect()->assertSessionHasErrors('quantity')
            ->assertSessionHas('cart.'.$variant->id, 10000);
        $this->assertSame(10000, ShoppingCart::sole()->items[$variant->id]);
        $this->assertSame([20000, 0], [$variant->fresh()->stock, $variant->fresh()->reserved]);
    }

    public function test_inactive_product_is_removed_when_a_saved_cart_is_restored(): void
    {
        $customer = $this->customer();
        $variant = $this->variant();
        ShoppingCart::create(['user_id' => $customer->id, 'items' => [$variant->id => 2]]);
        $variant->product->update(['active' => false]);
        $this->actingAs($customer)->get('/cart')->assertOk()->assertSessionHas('cart', []);
        $this->assertSame([], ShoppingCart::where('user_id', $customer->id)->sole()->items);
    }

    public function test_uncertain_checkout_consumes_saved_cart_once_and_keeps_original_invoice(): void
    {
        $customer = $this->customer();
        $variant = $this->variant();
        $this->mock(PayMongo::class, function (MockInterface $mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('create')->once()->andThrow(new \RuntimeException('Provider outcome uncertain'));
        });
        $this->actingAs($customer)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2]);
        $this->post('/checkout', ['recipient' => 'Test Customer', 'phone' => '09123456789', 'delivery_method' => 'pickup', 'terms' => '1'])
            ->assertRedirect('/orders/1');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame([], ShoppingCart::where('user_id', $customer->id)->sole()->items);
        $this->assertSame(2, $variant->fresh()->reserved);
        $this->post('/checkout', ['recipient' => 'Test Customer', 'phone' => '09123456789', 'delivery_method' => 'pickup', 'terms' => '1'])
            ->assertRedirect('/orders/1');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame([], ShoppingCart::where('user_id', $customer->id)->sole()->items);
    }

    public function test_open_browser_sees_saved_cart_changes_from_another_browser(): void
    {
        $customer = $this->customer();
        $first = $this->variant('first');
        $second = $this->variant('second');
        $this->actingAs($customer)->post('/cart', ['variant_id' => $first->id, 'quantity' => 2]);
        ShoppingCart::where('user_id', $customer->id)->sole()->update(['items' => [$second->id => 4]]);
        $this->get('/cart')->assertOk()->assertSessionHas('cart', [$second->id => 4]);
        $this->post('/cart', ['variant_id' => $first->id, 'quantity' => 1]);
        $this->assertSame([$second->id => 4, $first->id => 1], ShoppingCart::where('user_id', $customer->id)->sole()->items);
    }

    public function test_checkout_consumption_keeps_new_quantities_saved_by_another_request(): void
    {
        $customer = $this->customer();
        $first = $this->variant('first');
        $second = $this->variant('second');
        $this->actingAs($customer)->post('/cart', ['variant_id' => $first->id, 'quantity' => 2]);
        $request = \Illuminate\Http\Request::create('/checkout', 'POST');
        $request->setUserResolver(fn () => $customer);
        $request->setLaravelSession($this->app['session.store']);
        ShoppingCart::where('user_id', $customer->id)->sole()->update(['items' => [$first->id => 5, $second->id => 3]]);
        app(SavedCart::class)->consume($request, [$first->id => 2]);
        $this->assertSame([$first->id => 3, $second->id => 3], ShoppingCart::where('user_id', $customer->id)->sole()->items);
        $this->assertSame([$first->id => 3, $second->id => 3], $request->session()->get('cart'));
        $this->assertTrue($request->attributes->get('saved_cart_consumed'));
    }

    public function test_overlapping_browser_additions_do_not_silently_clip_the_saved_quantity_limit(): void
    {
        $customer = $this->customer();
        $variant = $this->variant();
        $variant->update(['stock' => 20000]);
        $this->actingAs($customer)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 5000]);
        $request = \Illuminate\Http\Request::create('/cart', 'POST');
        $request->setUserResolver(fn () => $customer);
        $request->setLaravelSession($this->app['session.store']);
        $route = (new \Illuminate\Routing\Route('POST', 'cart', fn () => null))->name('cart.add');
        $request->setRouteResolver(fn () => $route);
        app(SavedCart::class)->open($request);
        $request->session()->put('cart', [$variant->id => 8000]);
        ShoppingCart::where('user_id', $customer->id)->sole()->update(['items' => [$variant->id => 8000]]);

        try {
            app(SavedCart::class)->close($request);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
            $this->assertSame(8000, ShoppingCart::where('user_id', $customer->id)->sole()->items[$variant->id]);

            return;
        }
        $this->fail('An overlapping addition must be rejected instead of silently reducing the requested quantity.');
    }

    public function test_payment_callback_does_not_open_or_mutate_browser_cart_storage(): void
    {
        $this->mock(SavedCart::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('open');
            $mock->shouldNotReceive('close');
        });
        $this->postJson('/webhooks/paymongo', [])->assertUnauthorized();
        $this->assertDatabaseCount('shopping_carts', 0);
    }

    public function test_rejected_overlapping_addition_is_not_logged_as_a_completed_cart_action(): void
    {
        $customer = $this->customer();
        $variant = $this->variant();
        $variant->update(['stock' => 20000]);
        $this->actingAs($customer)->post('/cart', ['variant_id' => $variant->id, 'quantity' => 5000]);
        $eventsBefore = \App\Models\ActivityLog::count();
        $this->partialMock(SavedCart::class, function (MockInterface $mock) use ($customer, $variant) {
            $mock->shouldReceive('close')->once()->andReturnUsing(function ($request) use ($customer, $variant) {
                ShoppingCart::where('user_id', $customer->id)->sole()->update(['items' => [$variant->id => 8000]]);
                (new SavedCart)->close($request);
            });
        });

        $this->from('/cart')->post('/cart', ['variant_id' => $variant->id, 'quantity' => 3000])
            ->assertRedirect('/cart')->assertSessionHasErrors('quantity')->assertSessionMissing('notice');
        $this->assertSame(8000, ShoppingCart::where('user_id', $customer->id)->sole()->items[$variant->id]);
        $this->assertSame($eventsBefore, \App\Models\ActivityLog::count());
    }
}
