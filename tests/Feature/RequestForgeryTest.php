<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequestForgeryTest extends TestCase
{
    use RefreshDatabase;

    private string $originalEnvironment;

    private const SESSION_TOKEN = 'request-forgery-test-session-token';

    private const CROSS_SITE = ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://untrusted.example'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = $this->app->environment();
        $this->assertSame('testing', $this->originalEnvironment);
        // Keep the isolated test database/session configuration; enable the real middleware.
        $this->app['env'] = 'local';
        $this->assertFalse($this->app->runningUnitTests());
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_request_forgery',
            'shop.paymongo.webhook_secret' => 'request-forgery-webhook-test']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->originalEnvironment) && $this->app) {
            $this->app['env'] = $this->originalEnvironment;
        }
        parent::tearDown();
    }

    private function variant(): Variant
    {
        $product = Product::create(['name' => 'Request Protection Tee', 'slug' => 'request-protection-tee', 'category' => '', 'description' => 'Request protection fixture']);

        return $product->variants()->create(['sku' => 'REQUEST-PROTECTION-M-WHITE', 'size' => 'M', 'color' => 'White', 'stock' => 20,
            'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
    }

    private function order(User $customer, Variant $variant, bool $paid = true): Order
    {
        $flow = app(OrderFlow::class);
        $order = $flow->reserve([$variant->id => 2], ['recipient' => 'Request Test Customer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0], $customer->id);
        $order->update(['checkout_session_id' => 'cs_request_forgery_'.$order->id]);
        if ($paid) {
            DB::transaction(fn () => $flow->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_request_forgery_'.$order->id));
            $order->refresh()->update(['status' => 'ready']);
        }

        return $order->fresh();
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['orders', 'order_items', 'variants', 'order_reviews', 'refunds', 'activity_logs', 'webhook_events', 'payment_attempts'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    public static function protectedRequests(): array
    {
        $cases = [];
        foreach (['receipt', 'staff_status', 'payment_close', 'refund', 'checkout', 'delivery_quote', 'invoice_online', 'invoice_cash', 'walk_in', 'invoice_due', 'invoice_terms'] as $operation) {
            foreach (['missing', 'wrong'] as $token) {
                $cases[$operation.'_'.$token] = [$operation, $token];
            }
        }

        return $cases;
    }

    #[DataProvider('protectedRequests')]
    public function test_cross_site_mutations_without_a_matching_token_are_rejected_before_state_or_provider_work(string $operation, string $token): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $variant = $this->variant();
        $order = $this->order($customer, $variant, $operation !== 'payment_close');
        $actor = $customer;
        $method = 'POST';
        $payload = [];
        switch ($operation) {
            case 'receipt':
                $url = '/orders/'.$order->id.'/receipt';
                $payload = ['received' => '1', 'rating' => 5, 'comment' => 'The clothing arrived and I checked all pieces.'];
                break;
            case 'staff_status':
                $order->update(['status' => 'packing']);
                $actor = User::factory()->create(['role' => 'staff']);
                $method = 'PATCH';
                $url = '/admin/orders/'.$order->id;
                $payload = ['status' => 'ready', 'reason' => 'Every piece has been packed and checked.'];
                break;
            case 'payment_close':
                $url = '/orders/'.$order->id.'/payment/close';
                break;
            case 'refund':
                $order->update(['status' => 'completed', 'customer_confirmed_at' => now()]);
                $order->review()->create(['user_id' => $customer->id, 'rating' => 4, 'comment' => 'The order arrived but the size is not suitable.']);
                $url = '/orders/'.$order->id.'/refunds';
                $payload = ['reason' => 'The delivered clothing size does not fit me.'];
                break;
            case 'checkout':
                $url = '/checkout';
                $payload = ['recipient' => 'Request Test Customer', 'phone' => '09123456789', 'delivery_method' => 'pickup'];
                break;
            case 'delivery_quote':
                $url = '/checkout/quote';
                $payload = ['street_address' => '12 Example Street', 'city' => 'Quezon City', 'province' => 'Metro Manila',
                    'postal_code' => '1100', 'country' => 'Philippines', 'lat' => '14.676', 'lng' => '121.0437'];
                break;
            case 'invoice_online':
                $url = '/orders/'.$order->id.'/payments';
                $payload = ['amount' => '200', 'request_key' => (string) Str::uuid()];
                break;
            case 'invoice_cash':
                $actor = User::factory()->create(['role' => 'staff']);
                $url = '/admin/orders/'.$order->id.'/payments/cash';
                $payload = ['amount' => '200', 'request_key' => (string) Str::uuid(), 'reason' => 'Cash counted and received at counter.', 'payment_received' => '1'];
                break;
            case 'walk_in':
                $actor = User::factory()->create(['role' => 'staff']);
                $url = '/admin/walk-in';
                $payload = ['customer_type' => 'guest', 'recipient' => 'Test guest', 'payment_option' => 'full_cash', 'request_key' => (string) Str::uuid(), 'payment_received' => '1', 'reason' => 'Cash counted and received at counter.', 'lines' => [['variant_id' => $variant->id, 'quantity' => 1]]];
                break;
            case 'invoice_due':
                $actor = User::factory()->create(['role' => 'admin']);
                $url = '/admin/orders/'.$order->id.'/due';
                $payload = ['due_at' => now()->addDays(14)->toDateString(), 'reason' => 'Customer and owner agreed a later deadline.'];
                break;
            case 'invoice_terms':
                $actor = User::factory()->create(['role' => 'admin']);
                $url = '/admin/settings/payment-terms';
                $payload = ['days' => 14, 'reason' => 'Owner updated terms for future invoices.'];
                break;
        }
        if ($token === 'wrong') {
            $payload['_token'] = 'another-session-token';
        }
        $cart = [$variant->id => 1];
        $before = $this->snapshot();
        $this->actingAs($actor)->withSession(['_token' => self::SESSION_TOKEN, 'cart' => $cart])
            ->json($method, $url, $payload, self::CROSS_SITE)->assertStatus(419)->assertSessionHas('cart', $cart);
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_valid_form_token_permits_customer_receipt_and_required_review(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $variant = $this->variant();
        $order = $this->order($customer, $variant);
        $money = $order->only(['total', 'subtotal', 'shipping', 'payment_status', 'payment_id']);
        $inventory = $variant->fresh()->only(['stock', 'reserved']);
        $this->actingAs($customer)->withSession(['_token' => self::SESSION_TOKEN])->post('/orders/'.$order->id.'/receipt',
            ['_token' => self::SESSION_TOKEN, 'received' => '1', 'rating' => 5, 'comment' => 'The clothing arrived and I checked all pieces.'], self::CROSS_SITE)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->customer_confirmed_at);
        $this->assertDatabaseCount('order_reviews', 1);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.received', 'target_id' => $order->id, 'actor_id' => $customer->id]);
        $this->assertSame($money, $order->fresh()->only(array_keys($money)));
        $this->assertSame($inventory, $variant->fresh()->only(['stock', 'reserved']));
        Http::assertNothingSent();
    }

    public function test_valid_header_token_permits_a_staff_change_with_its_reason_and_audit(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order($customer, $this->variant());
        $order->update(['status' => 'packing']);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->withSession(['_token' => self::SESSION_TOKEN])->patchJson('/admin/orders/'.$order->id,
            ['status' => 'ready', 'reason' => 'Every piece has been packed and checked.'], self::CROSS_SITE + ['X-CSRF-TOKEN' => self::SESSION_TOKEN])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.status_changed', 'target_id' => $order->id, 'actor_id' => $staff->id]);
        Http::assertNothingSent();
    }

    public function test_unsigned_webhook_is_exempt_from_browser_csrf_but_still_requires_provider_authentication(): void
    {
        $before = $this->snapshot();
        $this->withSession(['_token' => self::SESSION_TOKEN])->postJson('/webhooks/paymongo', ['data' => []], self::CROSS_SITE)
            ->assertUnauthorized();
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }
}
