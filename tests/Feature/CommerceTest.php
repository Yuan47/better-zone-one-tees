<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Forecast;
use App\Services\Lalamove;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use App\Services\Pricing;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use RefreshDatabase;

    private function variant(int $stock = 500): Variant
    {
        $product = Product::create(['name' => 'Sample Tee', 'slug' => 'sample-'.uniqid(), 'category' => 'Essentials', 'description' => 'Sample', 'art_color' => '#ccbb99']);

        return $product->variants()->create(['sku' => 'TEE-'.uniqid(), 'size' => 'M', 'color' => 'Ivory', 'stock' => $stock,
            'retail_price' => 25000, 'wholesale_price' => 20000, 'reseller_price' => 18000]);
    }

    private function user(string $role = 'customer'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function reserved(Variant $v, User $u, int $qty = 6): Order
    {
        return app(OrderFlow::class)->reserve([$v->id => $qty], ['recipient' => 'Sample Buyer', 'phone' => '09123456789', 'address' => 'Pickup', 'delivery_method' => 'pickup', 'shipping' => 0], $u->id);
    }

    private function payConfig(): void
    {
        config(['shop.paymongo.secret' => 'sk_test_demo', 'shop.paymongo.webhook_secret' => 'webhook-secret', 'shop.paymongo.mode' => 'test']);
    }

    private function envelope(Order $order, string $event = 'evt_demo', ?int $amount = null): array
    {
        return ['data' => ['id' => $event, 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false,
            'data' => ['id' => 'cs_demo', 'attributes' => ['reference_number' => $order->number,
                'payments' => [['id' => 'pay_demo', 'attributes' => ['status' => 'paid', 'currency' => 'PHP', 'amount' => $amount ?? $order->total]]]]]]]];
    }

    private function webhook(array $data, bool $valid = true)
    {
        $body = json_encode($data);
        $t = (string) time();
        $signature = hash_hmac('sha256', $t.'.'.$body, $valid ? 'webhook-secret' : 'wrong');

        return $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => 't='.$t.',te='.$signature.',li='], $body);
    }

    public static function boundaries(): array
    {
        return [[1, 'retail', 25000], [5, 'retail', 125000], [6, 'wholesale', 120000], [50, 'wholesale', 1000000], [51, 'reseller', 918000], [100, 'reseller', 1800000], [101, 'bulk', 1727100]];
    }

    #[DataProvider('boundaries')]
    public function test_quantity_boundaries(int $quantity, string $tier, int $expected): void
    {
        $v = $this->variant();
        $cart = app(Pricing::class)->cart([$v->id => $quantity]);
        $this->assertSame($tier, $cart['tier']);
        $this->assertSame($expected, $cart['subtotal']);
    }

    public function test_mixed_cart_uses_total_quantity_and_bulk_rounds_once(): void
    {
        $v = $this->variant();
        $v2 = $this->variant();
        $v2->update(['reseller_price' => 17999]);
        $cart = app(Pricing::class)->cart([$v->id => 50, $v2->id => 51]);
        $base = 50 * 18000 + 51 * 17999;
        $this->assertSame('bulk', $cart['tier']);
        $this->assertSame((int) round($base * .05), $cart['discount']);
        $this->assertSame($base - $cart['discount'], $cart['subtotal']);
    }

    public function test_store_and_admin_views_render(): void
    {
        $v = $this->variant();
        $this->get('/')->assertOk()->assertSee('Sample Tee');
        $this->get('/products/'.$v->product->slug)->assertOk();
        $this->actingAs($this->user('admin'));
        foreach (['/admin', '/admin/orders', '/admin/products', '/admin/products/create', '/admin/products/'.$v->product_id, '/admin/customers', '/admin/settings'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_customer_and_staff_permissions(): void
    {
        $this->actingAs($this->user())->get('/admin')->assertForbidden();
        $this->postJson('/chat', ['message' => 'Show store data', 'side' => 'staff'])->assertForbidden();
        $this->actingAs($this->user('staff'))->get('/admin')->assertOk();
        foreach (['/admin/products', '/admin/customers', '/admin/settings'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_customers_cannot_access_other_orders_or_chat_order_status(): void
    {
        $v = $this->variant();
        $owner = $this->user();
        $o = $this->reserved($v, $owner);
        $this->actingAs($this->user())->get('/orders/'.$o->id)->assertForbidden();
        config(['shop.grok.key' => null]);
        $this->postJson('/chat', ['message' => 'Track '.$o->number, 'side' => 'customer'])->assertOk()->assertDontSee('awaiting payment');
    }

    public function test_cart_price_is_server_computed_and_wishlist_toggles(): void
    {
        $v = $this->variant();
        $this->post('/cart', ['variant_id' => $v->id, 'quantity' => 6, 'price' => 1])->assertRedirect(route('cart'));
        $this->get('/cart')->assertOk()->assertSee('1,200.00')->assertSee('Wholesale');
        $u = $this->user();
        $this->actingAs($u);
        $this->post('/wishlist/'.$v->product_id)->assertRedirect();
        $this->assertDatabaseCount('wishlists', 1);
        $this->get('/?wishlist=1')->assertSee('Sample Tee');
        $this->post('/wishlist/'.$v->product_id)->assertRedirect();
        $this->assertDatabaseCount('wishlists', 0);
    }

    public function test_unconfigured_checkout_never_creates_order(): void
    {
        config(['shop.paymongo.secret' => null]);
        $v = $this->variant();
        $this->actingAs($this->user())->withSession(['cart' => [$v->id => 1]]);
        $this->get('/checkout')->assertOk()->assertSee('awaiting configuration');
        $this->post('/checkout', ['recipient' => 'Buyer', 'phone' => '09123456789', 'address' => 'Pickup', 'delivery_method' => 'pickup'])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_reservations_prevent_overselling(): void
    {
        $v = $this->variant(7);
        $u = $this->user();
        $this->reserved($v, $u, 6);
        $this->assertSame(1, $v->fresh()->available());
        $this->expectException(ValidationException::class);
        $this->reserved($v, $u, 2);
    }

    public function test_verified_webhook_commits_inventory_once(): void
    {
        $this->payConfig();
        $v = $this->variant(10);
        $o = $this->reserved($v, $this->user());
        $o->update(['checkout_session_id' => 'cs_demo']);
        $body = $this->envelope($o);
        $this->webhook($body)->assertOk();
        $this->webhook($body)->assertOk();
        $this->assertSame('paid', $o->fresh()->payment_status);
        $this->assertSame(4, $v->fresh()->stock);
        $this->assertSame(0, $v->fresh()->reserved);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_signature_amount_reference_mode_must_match(): void
    {
        $this->payConfig();
        $v = $this->variant();
        $o = $this->reserved($v, $this->user());
        $o->update(['checkout_session_id' => 'cs_demo']);
        $this->webhook($this->envelope($o), false)->assertUnauthorized();
        $this->webhook($this->envelope($o, 'evt_wrong', 1))->assertStatus(409);
        $body = $this->envelope($o);
        $body['data']['attributes']['livemode'] = true;
        $this->webhook($body)->assertStatus(422);
        $body = $this->envelope($o);
        $body['data']['attributes']['data']['attributes']['reference_number'] = 'WRONG';
        $this->webhook($body)->assertStatus(409);
        $this->assertSame('pending', $o->fresh()->payment_status);
        $this->assertFalse($o->fresh()->inventory_committed);
    }

    public function test_current_webhook_envelope_and_redirect_do_not_mark_paid(): void
    {
        $this->payConfig();
        $v = $this->variant();
        $u = $this->user();
        $o = $this->reserved($v, $u);
        $o->update(['checkout_session_id' => 'cs_demo']);
        $this->actingAs($u)->get('/orders/'.$o->id.'?status=paid')->assertOk();
        $this->assertSame('pending', $o->fresh()->payment_status);
        $session = $this->envelope($o)['data']['attributes']['data'];
        $this->webhook(['event_type' => 'send.webhook', 'data' => ['type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => $session]])->assertOk();
        $this->assertSame('paid', $o->fresh()->payment_status);
    }

    public function test_expired_signatures_and_session_race_rejected(): void
    {
        $this->payConfig();
        $v = $this->variant();
        $o = $this->reserved($v, $this->user());
        $this->webhook($this->envelope($o))->assertStatus(409);
        $body = '{}';
        $t = (string) (time() - 600);
        $sig = hash_hmac('sha256', $t.'.'.$body, 'webhook-secret');
        $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => 't='.$t.',te='.$sig], $body)->assertUnauthorized();
    }

    public function test_paymongo_payload_preserves_exact_bulk_discount_and_shipping(): void
    {
        $this->payConfig();
        $v = $this->variant();
        $o = app(OrderFlow::class)->reserve([$v->id => 101], ['recipient' => 'Buyer', 'phone' => '09123456789', 'address' => 'Sample', 'delivery_method' => 'lalamove', 'shipping' => 12345], $this->user()->id);
        Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'cs_test', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test']]])]);
        $result = app(PayMongo::class)->create($o->load('items', 'user'));
        $this->assertSame('cs_test', $result['checkout_session_id']);
        Http::assertSent(function ($request) use ($o) {
            $attrs = $request['data']['attributes'];
            $sum = 0;
            foreach ($attrs['line_items'] as $line) {
                $sum += $line['amount'] * $line['quantity'];
            }

            return $sum === $o->total && $attrs['pass_on_fees'] === false && $request->url() === 'https://api.paymongo.com/v2/checkout_sessions';
        });
    }

    public function test_live_provider_keys_blocked_in_demo(): void
    {
        config(['shop.demo' => true, 'shop.paymongo.mode' => 'live', 'shop.paymongo.secret' => 'sk_live_x', 'shop.paymongo.webhook_secret' => 'wh_x', 'app.url' => 'https://store.test']);
        $this->assertFalse(app(PayMongo::class)->ready());
    }

    public function test_lalamove_signature_covers_exact_request_body(): void
    {
        config(['shop.lalamove.mode' => 'sandbox', 'shop.lalamove.key' => 'pk_test_x', 'shop.lalamove.secret' => 'sk_test_x',
            'shop.lalamove.address' => 'Store', 'shop.lalamove.lat' => '14.1', 'shop.lalamove.lng' => '121.1', 'shop.lalamove.phone' => '09123456789']);
        Http::fake(['rest.sandbox.lalamove.com/*' => Http::response(['data' => ['quotationId' => 'q_test', 'expiresAt' => now()->addMinutes(5)->toIso8601String(), 'priceBreakdown' => ['currency' => 'PHP', 'total' => '100.00']]])]);
        app(Lalamove::class)->quote('Customer', '14.2', '121.2');
        Http::assertSent(function ($req) {
            $header = $req->header('Authorization')[0];
            $parts = explode(':', substr($header, 5));
            $expected = hash_hmac('sha256', $parts[1]."\r\nPOST\r\n/v3/quotations\r\n\r\n".$req->body(), 'sk_test_x');

            return hash_equals($expected, $parts[2]) && $req->header('Market')[0] === 'PH';
        });
    }

    public function test_staff_cannot_advance_unpaid_orders(): void
    {
        $o = $this->reserved($this->variant(), $this->user());
        $this->actingAs($this->user('staff'))->patch('/admin/orders/'.$o->id, ['status' => 'packing'])->assertStatus(422);
    }

    public function test_admin_stock_cannot_be_less_than_reserved(): void
    {
        $v = $this->variant();
        $this->reserved($v, $this->user(), 6);
        $this->actingAs($this->user('admin'))->patch('/admin/products/'.$v->product_id.'/variants/'.$v->id, [
            'sku' => $v->sku, 'size' => 'M', 'color' => 'Ivory', 'stock' => 5, 'reorder_level' => 10, 'retail' => 250, 'wholesale' => 200, 'reseller' => 180,
        ])->assertSessionHasErrors('stock');
        $this->assertSame(500, $v->fresh()->stock);
    }

    public function test_forecast_formulas_and_zero_baseline(): void
    {
        $f = app(Forecast::class);
        $fit = $f->linear([100, 120, 140]);
        $this->assertEqualsWithDelta(80, $fit['a'], .00001);
        $this->assertEqualsWithDelta(20, $fit['b'], .00001);
        $this->assertEqualsWithDelta(160, $fit['prediction'], .00001);
        $growth = $f->growth([100, 120]);
        $this->assertEqualsWithDelta(.2, $growth['rate'], .00001);
        $this->assertEqualsWithDelta(144, $growth['prediction'], .00001);
        $this->assertNull($f->growth([0, 120])['prediction']);
        $this->assertNull($f->linear([10, 20])['prediction']);
        $this->assertSame(0, $f->linear([30, 20, 10])['prediction']);
    }

    public function test_checkout_creates_one_pending_order_with_server_prices(): void
    {
        $this->payConfig();
        $v = $this->variant();
        $u = $this->user();
        Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'cs_new', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_new']]])]);
        $this->actingAs($u)->withSession(['cart' => [$v->id => 101]]);
        $details = ['recipient' => 'Buyer', 'phone' => '09123456789', 'address' => 'Pickup', 'delivery_method' => 'pickup', 'total' => 1];
        $this->post('/checkout', $details)->assertRedirect('https://checkout.paymongo.com/cs_new');
        $o = Order::firstOrFail();
        $this->assertSame(1727100, $o->total);
        $this->assertSame('pending', $o->payment_status);
        $this->assertSame(101, $v->fresh()->reserved);
        $this->post('/checkout', $details)->assertRedirect(route('orders.show', $o));
        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(1);
    }

    public function test_registration_does_not_accept_admin_role_and_sales_export_works(): void
    {
        $this->post('/register', ['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => 'LongPassword!2026', 'password_confirmation' => 'LongPassword!2026', 'role' => 'admin'])->assertRedirect();
        $this->assertSame('customer', User::where('email', 'buyer@example.test')->firstOrFail()->role);
        $this->actingAs($this->user('admin'));
        $response = $this->get('/admin/reports/export')->assertOk();
        $this->assertStringContainsString('Net clothing PHP', $response->streamedContent());
    }

    public function test_forecast_uses_complete_months_only(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 12:00:00', 'Asia/Manila'));
        $v = $this->variant();
        $u = $this->user();
        foreach ([['2026-07-15', 10], ['2026-08-15', 20], ['2026-09-15', 30], ['2026-10-01', 100]] as [$date,$qty]) {
            $o = $this->reserved($v, $u, $qty);
            $o->update(['payment_status' => 'paid', 'paid_at' => $date.' 12:00:00', 'status' => 'completed']);
        }
        $f = app(Forecast::class);
        $row = $f->rows()[0];
        $this->assertSame([10, 20, 30], $row['values']);
        $this->assertSame(40, $row['forecast']);
        $this->assertEquals(45, $row['growth']['prediction']);
        $this->assertSame('October 2026', $row['target']);
        $this->assertSame(['2026-07', '2026-08', '2026-09'], $row['months']);
        $this->travelBack();
    }
}
