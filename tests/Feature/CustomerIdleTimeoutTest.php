<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerSessions;
use App\Services\OrderFlow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerIdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 12:00:00', 'Asia/Manila'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function customer(): User
    {
        $user = User::factory()->create(['password' => 'IdleCustomer!2026']);
        $this->post('/login', ['email' => $user->email, 'password' => 'IdleCustomer!2026'])->assertRedirect('/');

        return $user->refresh();
    }

    public static function sessionDrivers(): array
    {
        return [['array'], ['file'], ['database']];
    }

    #[DataProvider('sessionDrivers')]
    public function test_server_expires_customer_at_exactly_fifteen_minutes_without_javascript(string $driver): void
    {
        config(['session.driver' => $driver]);
        $user = $this->customer();
        $this->assertSame($user->id, session('customer_idle.user_id'));
        $this->assertSame(now()->timestamp + 900, session('customer_idle.expires_at'));
        $this->travel(900)->seconds();
        Auth::forgetGuards();
        $this->get('/account')->assertRedirect('/login')->assertSessionHas('notice');
        $this->assertGuest();
        $this->assertNull(session(CustomerSessions::SESSION_KEY));
        $this->get('/account')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_registration_seeds_a_customer_deadline_and_only_customers_receive_idle_interface(): void
    {
        $this->post('/register', ['name' => 'Idle customer', 'email' => 'idle-register@example.test',
            'password' => 'IdleCustomer!2026', 'password_confirmation' => 'IdleCustomer!2026'])->assertRedirect('/');
        $this->assertSame(now()->timestamp + 900, session('customer_idle.expires_at'));
        Auth::forgetGuards();
        $this->get('/account')->assertOk()->assertSee('customer-session.js')->assertSee('Stay signed in');
        foreach (['staff', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/account')->assertOk()->assertDontSee('customer-session.js');
            $this->travel(1)->hours();
            $this->get('/admin')->assertOk();
            $this->postJson('/session/activity')->assertForbidden();
        }
    }

    public function test_navigation_and_explicit_activity_extend_only_an_unexpired_customer_session(): void
    {
        $this->customer();
        $initial = session('customer_idle.expires_at');
        $this->travel(899)->seconds();
        $this->get('/account')->assertOk();
        $this->assertSame($initial + 899, session('customer_idle.expires_at'));
        $this->travel(800)->seconds();
        $this->postJson('/session/activity', ['idle_seconds' => 20])->assertOk()
            ->assertJsonPath('expires_at', now()->timestamp - 20 + 900);
        $this->travel(880)->seconds();
        $this->postJson('/session/activity', ['idle_seconds' => 0])->assertUnauthorized()
            ->assertJsonPath('code', 'customer_session_expired');
        $this->assertGuest();
    }

    public function test_background_json_or_fetch_requests_do_not_keep_an_idle_session_alive(): void
    {
        $this->customer();
        $expiry = session('customer_idle.expires_at');
        foreach ([300, 300, 299] as $advance) {
            $this->travel($advance)->seconds();
            $this->getJson('/account')->assertOk();
            $this->postJson('/session/status')->assertOk()->assertJsonPath('expires_at', $expiry);
            $this->withHeader('Sec-Fetch-Dest', 'empty')->get('/account')->assertOk();
            $this->assertSame($expiry, session('customer_idle.expires_at'));
        }
        $this->travel(1)->seconds();
        $this->postJson('/session/status')->assertUnauthorized()->assertJsonPath('code', 'customer_session_expired');
        $this->assertGuest();
    }

    public function test_stale_tab_state_cannot_shorten_an_active_shared_deadline_or_revive_an_expired_session(): void
    {
        $user = $this->customer();
        $stale = session(CustomerSessions::SESSION_KEY);
        $this->travel(600)->seconds();
        $this->postJson('/session/activity')->assertOk()->assertJsonPath('expires_at', now()->timestamp + 900);
        $this->withSession([CustomerSessions::SESSION_KEY => $stale]);
        $this->travel(300)->seconds();
        $this->postJson('/session/status')->assertOk()->assertJsonPath('expires_at', $stale['expires_at'] + 600);
        $this->travel(600)->seconds();
        $this->postJson('/session/status')->assertUnauthorized();
        // An older request may save its original session after logout; the shared tombstone still denies it.
        $this->actingAs($user)->withSession([CustomerSessions::SESSION_KEY => $stale]);
        $this->postJson('/session/activity')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_cache_eviction_never_grants_a_fresh_window_to_an_expired_session(): void
    {
        $this->customer();
        $this->postJson('/session/status')->assertOk();
        $state = session(CustomerSessions::SESSION_KEY);
        Cache::forget('customer-idle:'.hash('sha256', $state['user_id'].'|'.$state['id']));
        $this->travel(900)->seconds();
        $this->postJson('/session/activity')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_unverified_customers_can_record_activity_but_still_cannot_bypass_verification(): void
    {
        $user = $this->customer();
        $user->forceFill(['email_verified_at' => null])->save();
        config(['auth.email_verification.required' => true]);
        Auth::forgetGuards();
        $this->get('/email/verify')->assertOk()->assertSee('customer-session.js');
        $this->travel(840)->seconds();
        $this->postJson('/session/activity')->assertOk();
        $this->getJson('/orders')->assertForbidden()->assertJsonPath('message', 'Verify your email before continuing.');
        $this->travel(900)->seconds();
        $this->postJson('/session/activity')->assertUnauthorized();
    }

    public function test_expiry_leaves_invoice_payments_stock_and_reservations_unchanged(): void
    {
        $user = $this->customer();
        $product = Product::create(['name' => 'Idle fixture', 'slug' => 'idle-fixture', 'description' => 'Test', 'category' => '']);
        $variant = $product->variants()->create(['sku' => 'IDLE-M-WHITE', 'size' => 'M', 'color' => 'White',
            'stock' => 10, 'retail_price' => 20000, 'wholesale_price' => 18000, 'reseller_price' => 16000]);
        $order = app(OrderFlow::class)->reserve([$variant->id => 2], ['recipient' => 'Idle customer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0], $user->id);
        $before = $order->fresh()->getAttributes();
        $this->travel(900)->seconds();
        $this->postJson('/orders/'.$order->id.'/payment/close')->assertUnauthorized();
        $this->assertSame($before, Order::findOrFail($order->id)->getAttributes());
        $this->assertSame(10, $variant->fresh()->stock);
        $this->assertSame(2, $variant->fresh()->reserved);
        $this->assertSame(0, DB::table('payment_attempts')->count());
        Http::assertNothingSent();
    }

    public function test_activity_requires_csrf_and_rejects_invalid_idle_age_without_extending_deadline(): void
    {
        $this->customer();
        $expiry = session('customer_idle.expires_at');
        $this->travel(200)->seconds();
        $this->postJson('/session/activity', ['idle_seconds' => -1])->assertUnprocessable();
        $this->assertSame($expiry, session('customer_idle.expires_at'));
        $environment = $this->app['env'];
        $this->app['env'] = 'local';
        try {
            $this->withSession(['_token' => 'idle-csrf-token'])->postJson('/session/activity')->assertStatus(419);
            $this->assertSame($expiry, session('customer_idle.expires_at'));
        } finally {
            $this->app['env'] = $environment;
        }
    }

    public function test_expired_safe_get_returns_to_its_page_after_login_without_replaying_mutations(): void
    {
        $user = $this->customer();
        $this->travel(900)->seconds();
        $this->get('/orders')->assertRedirect('/login')->assertSessionHas('url.intended', '/orders');
        $this->post('/login', ['email' => $user->email, 'password' => 'IdleCustomer!2026'])->assertRedirect('/orders');
        $this->travel(900)->seconds();
        $this->postJson('/session/activity')->assertUnauthorized()->assertSessionMissing('url.intended');
    }

    public function test_cache_outage_blocks_activity_without_extending_the_session_but_explicit_logout_still_works(): void
    {
        $this->customer();
        $expiry = session('customer_idle.expires_at');
        $state = session(CustomerSessions::SESSION_KEY);
        $key = 'customer-idle:'.hash('sha256', $state['user_id'].'|'.$state['id']).':lock';
        // Keep the real browser-session lock; only the idle-state cache is unavailable.
        Cache::swap(\Mockery::mock(Cache::getFacadeRoot())->makePartial());
        Cache::shouldReceive('lock')->with($key, 5)->twice()
            ->andThrow(new \RuntimeException('Simulated idle-state cache outage'));
        $this->travel(100)->seconds();
        $this->postJson('/session/activity')->assertStatus(503);
        $this->assertSame($expiry, session('customer_idle.expires_at'));
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
