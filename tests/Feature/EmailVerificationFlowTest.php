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
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.email_verification.required' => true,
            'services.brevo.api_key' => 'mock-verification-key',
            'services.brevo.from_email' => 'accounts@zoneone.example',
            'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
    }

    private function user(string $role = 'customer', bool $verified = false): User
    {
        return User::factory()->create(['role' => $role, 'email_verified_at' => $verified ? now() : null,
            'password' => 'VerificationPassword!2026']);
    }

    private function variant(): Variant
    {
        $product = Product::create(['name' => 'Verification tee', 'slug' => 'verification-tee',
            'category' => '', 'description' => 'Isolated verification fixture']);

        return $product->variants()->create(['sku' => 'VERIFICATION-WHITE-M', 'size' => 'M', 'color' => 'White',
            'stock' => 20, 'retail_price' => 25000, 'wholesale_price' => 20000, 'reseller_price' => 18000]);
    }

    private function order(?User $customer, Variant $variant): Order
    {
        return app(OrderFlow::class)->reserve([$variant->id => 5], ['recipient' => 'Verification buyer',
            'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0,
            'sales_channel' => $customer ? 'online' : 'walk_in'], $customer?->id);
    }

    private function financialState(): array
    {
        $state = [];
        foreach (['orders', 'order_items', 'variants', 'payment_attempts', 'refunds', 'order_reviews'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    private function acceptEmail(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<verification@brevo.example>'], 201)]);
    }

    private function deliveredCode(): string
    {
        $request = Http::recorded()->last()[0];
        $this->assertSame(1, preg_match('/\b([0-9]{6})\b/', $request['textContent'], $matches), 'The test provider should receive one six-digit verification code.');

        return $matches[1];
    }

    public static function roles(): array
    {
        return [['customer'], ['staff'], ['admin']];
    }

    public function test_verification_endpoints_require_a_signed_in_account(): void
    {
        $this->get('/email/verify')->assertRedirect('/login');
        $this->post('/email/verification-code')->assertRedirect('/login');
        $this->post('/email/verify', ['code' => '123456'])->assertRedirect('/login');
        Http::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_unverified_roles_can_read_their_account_and_public_store_without_sending_mail(string $role): void
    {
        $user = $this->user($role);
        $variant = $this->variant();
        $this->actingAs($user)->get('/')->assertOk();
        $this->get('/products/'.$variant->product->slug)->assertOk();
        $this->get('/cart')->assertOk();
        $this->get('/account')->assertOk()->assertSee($user->email);
        $this->get('/email/verify')->assertOk()->assertSee($user->email);
        $this->get('/email/verify')->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
        Http::assertNothingSent();
    }

    public static function blockedCustomerOperations(): array
    {
        return [
            ['GET', '/checkout'], ['GET', '/orders'], ['GET', '/orders/{order}'],
            ['POST', '/cart'], ['PATCH', '/cart/{variant}'], ['POST', '/wishlist/{product}'],
            ['POST', '/checkout'], ['POST', '/checkout/quote'], ['POST', '/chat'],
            ['POST', '/orders/{order}/payments'], ['POST', '/orders/{order}/payment/check'],
            ['POST', '/orders/{order}/payment/close'], ['POST', '/orders/{order}/receipt'],
            ['POST', '/orders/{order}/refunds'], ['PATCH', '/account'], ['PATCH', '/account/password'],
        ];
    }

    #[DataProvider('blockedCustomerOperations')]
    public function test_unverified_customers_cannot_mutate_or_open_private_commerce_before_verification(string $method, string $path): void
    {
        $user = $this->user();
        $variant = $this->variant();
        $order = $this->order($user, $variant);
        $path = str_replace(['{order}', '{variant}', '{product}'], [$order->id, $variant->id, $variant->product_id], $path);
        $before = $this->financialState();
        $cart = [$variant->id => 5];
        $this->actingAs($user)->withSession(['cart' => $cart])->call($method, $path, ['quantity' => 1, 'name' => 'Tampered',
            'username' => 'tampered', 'password' => 'TamperedPassword!2026', 'code' => '123456', 'email_verified_at' => now()->toDateTimeString()])
            ->assertRedirect('/email/verify')->assertSessionHas('cart', $cart);
        $this->assertSame($before, $this->financialState());
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertNull($user->fresh()->email_verified_at);
        Http::assertNothingSent();
    }

    public static function blockedStaffOperations(): array
    {
        return [
            ['staff', 'GET', '/admin'], ['staff', 'GET', '/admin/operations'], ['staff', 'GET', '/admin/walk-in'],
            ['staff', 'POST', '/admin/walk-in'], ['staff', 'POST', '/admin/orders/{order}/payments/cash'],
            ['staff', 'PATCH', '/admin/orders/{order}'], ['staff', 'POST', '/admin/orders/{order}/delivery'],
            ['admin', 'GET', '/admin'], ['admin', 'GET', '/admin/settings'], ['admin', 'GET', '/admin/activity'],
            ['admin', 'POST', '/admin/products'], ['admin', 'POST', '/admin/settings'],
            ['admin', 'POST', '/admin/settings/payment-terms'], ['admin', 'POST', '/admin/orders/{order}/due'],
        ];
    }

    #[DataProvider('blockedStaffOperations')]
    public function test_unverified_staff_and_owners_cannot_enter_or_change_operations(string $role, string $method, string $path): void
    {
        $user = $this->user($role);
        $order = $this->order($this->user(), $this->variant());
        $before = $this->financialState();
        $this->actingAs($user)->call($method, str_replace('{order}', $order->id, $path), ['status' => 'ready',
            'amount' => '1250', 'payment_received' => 1, 'reason' => 'Fixture reason for a forbidden operation.'])
            ->assertRedirect('/email/verify');
        $this->assertSame($before, $this->financialState());
        Http::assertNothingSent();
    }

    public function test_json_clients_get_a_useful_verification_response_without_changing_the_cart(): void
    {
        $this->actingAs($this->user())->withSession(['cart' => [42 => 3]])->postJson('/cart', ['variant_id' => 42, 'quantity' => 1])
            ->assertForbidden()->assertJsonPath('verification_url', route('verification.notice'))
            ->assertSessionHas('cart', [42 => 3]);
        Http::assertNothingSent();
    }

    public function test_public_guest_shopping_is_still_available(): void
    {
        $variant = $this->variant();
        $this->post('/cart', ['variant_id' => $variant->id, 'quantity' => 2])->assertRedirect();
        $this->get('/cart')->assertOk()->assertSee('Verification tee');
        $this->get('/checkout')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_unverified_login_redirects_without_sending_or_changing_the_password(): void
    {
        $user = $this->user();
        $password = $user->password;
        $this->post('/login', ['email' => $user->email, 'password' => 'VerificationPassword!2026'])
            ->assertRedirect('/email/verify');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertNull($user->fresh()->email_verified_at);
        Http::assertNothingSent();
    }

    public function test_registration_creates_an_unverified_customer_and_sends_one_code_when_ready(): void
    {
        $this->acceptEmail();
        $this->post('/register', ['name' => 'New Verification Customer', 'email' => 'new@example.test',
            'password' => 'VerificationPassword!2026', 'password_confirmation' => 'VerificationPassword!2026',
            'role' => 'admin', 'email_verified_at' => now()->toDateTimeString()])->assertRedirect('/email/verify');
        $user = User::where('email', 'new@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('customer', $user->role);
        $this->assertNull($user->email_verified_at);
        Http::assertSentCount(1);
        $this->assertSame(6, strlen($this->deliveredCode()));
        $this->get('/email/verify')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_disabled_local_mode_preserves_registration_and_login_without_sending_email(): void
    {
        config(['auth.email_verification.required' => false]);
        $user = $this->user();
        $this->post('/login', ['email' => $user->email, 'password' => 'VerificationPassword!2026'])->assertRedirect('/');
        $this->get('/orders')->assertOk();
        $this->post('/logout')->assertRedirect('/');
        $this->post('/register', ['name' => 'Local Customer', 'email' => 'local@example.test',
            'password' => 'VerificationPassword!2026', 'password_confirmation' => 'VerificationPassword!2026'])->assertRedirect('/');
        $this->assertNull(User::where('email', 'local@example.test')->sole()->email_verified_at);
        Http::assertNothingSent();
    }

    public function test_required_but_unconfigured_email_keeps_the_new_account_unverified_without_provider_work(): void
    {
        config(['services.brevo.api_key' => '']);
        $this->post('/register', ['name' => 'Waiting Customer', 'email' => 'waiting@example.test',
            'password' => 'VerificationPassword!2026', 'password_confirmation' => 'VerificationPassword!2026'])->assertRedirect('/email/verify');
        $this->assertNull(User::where('email', 'waiting@example.test')->sole()->email_verified_at);
        $this->get('/email/verify')->assertOk();
        $this->post('/email/verification-code')->assertRedirect('/email/verify');
        $this->get('/checkout')->assertRedirect('/email/verify');
        Http::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_verified_accounts_keep_their_existing_login_destination(string $role): void
    {
        $user = $this->user($role, true);
        $this->post('/login', ['email' => $user->email, 'password' => 'VerificationPassword!2026'])
            ->assertRedirect($role === 'customer' ? '/' : '/admin');
        Http::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_matching_code_verifies_the_current_account_and_uses_role_destination(string $role): void
    {
        $user = $this->user($role);
        $this->acceptEmail();
        $this->actingAs($user)->post('/email/verification-code')->assertRedirect('/email/verify');
        $code = $this->deliveredCode();
        $this->post('/email/verify', ['code' => $code])->assertRedirect($role === 'customer' ? '/' : '/admin')
            ->assertSessionHasNoErrors()->assertSessionMissing('_old_input.code');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->get($role === 'customer' ? '/orders' : '/admin')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_verification_returns_to_the_requested_private_get_without_changing_invoice_or_stock(): void
    {
        $user = $this->user();
        $order = $this->order($user, $this->variant());
        $before = $this->financialState();
        $this->acceptEmail();
        $this->actingAs($user)->get('/orders/'.$order->id)->assertRedirect('/email/verify');
        $this->post('/email/verification-code')->assertRedirect('/email/verify');
        $this->post('/email/verify', ['code' => $this->deliveredCode()])->assertRedirect('/orders/'.$order->id);
        $this->assertSame($before, $this->financialState());
        $this->get('/orders/'.$order->id)->assertOk();
    }

    public static function unsafeReturnPaths(): array
    {
        return [['https://untrusted.example/steal'], ['//untrusted.example/steal'], ['/\\untrusted.example'],
            ["/orders\r\nLocation: https://untrusted.example"], ['/email/verify'], ['/login'], ['/logout']];
    }

    #[DataProvider('unsafeReturnPaths')]
    public function test_successful_verification_does_not_redirect_to_an_external_or_authentication_return_path(string $path): void
    {
        $user = $this->user();
        $this->acceptEmail();
        $this->actingAs($user)->post('/email/verification-code')->assertRedirect('/email/verify');
        $this->withSession(['email_verification.return_to' => $path])->post('/email/verify', ['code' => $this->deliveredCode()])
            ->assertRedirect('/')->assertSessionMissing('email_verification.return_to');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_code_for_another_account_cannot_verify_the_signed_in_user(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->acceptEmail();
        $this->actingAs($first)->post('/email/verification-code')->assertRedirect('/email/verify');
        $code = $this->deliveredCode();
        $this->actingAs($second)->from('/email/verify')->post('/email/verify', ['code' => $code])
            ->assertRedirect('/email/verify')->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
        $this->assertNull($first->fresh()->email_verified_at);
        $this->assertNull($second->fresh()->email_verified_at);
        $this->actingAs($first)->post('/email/verify', ['code' => $code])->assertRedirect('/');
        $this->assertNotNull($first->fresh()->email_verified_at);
    }

    public function test_wrong_and_expired_codes_are_rejected_without_flashing_code_input(): void
    {
        $user = $this->user();
        $this->acceptEmail();
        $this->actingAs($user)->post('/email/verification-code')->assertRedirect('/email/verify');
        $code = $this->deliveredCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post('/email/verify', ['code' => $wrong])->assertRedirect('/email/verify')
            ->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->travel(11)->minutes();
        $this->post('/email/verify', ['code' => $code])->assertRedirect('/email/verify')
            ->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->travelBack();
        Http::assertSentCount(1);
    }

    public function test_resend_clicks_during_cooldown_do_not_send_duplicate_email(): void
    {
        $this->acceptEmail();
        $this->actingAs($this->user())->post('/email/verification-code')->assertRedirect('/email/verify');
        $this->post('/email/verification-code')->assertRedirect('/email/verify')->assertSessionHas('notice');
        $this->get('/email/verify')->assertOk();
        Http::assertSentCount(1);
    }

    public static function invalidCodes(): array
    {
        return [[''], ['12345'], ['1234567'], ['ABCDEF'], ['123.45'], [['123456']]];
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_code_input_never_verifies_or_flashes_the_submitted_code(mixed $code): void
    {
        $user = $this->user();
        $this->actingAs($user)->from('/email/verify')->post('/email/verify', ['code' => $code])
            ->assertRedirect('/email/verify')->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
        $this->assertNull($user->fresh()->email_verified_at);
        Http::assertNothingSent();
    }

    public function test_repeated_notice_visits_never_issue_codes_and_unverified_accounts_can_sign_out(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/email/verify')->assertOk();
        $this->get('/email/verify?code=123456')->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_profile_and_mass_assignment_cannot_forge_verification_when_optional(): void
    {
        config(['auth.email_verification.required' => false]);
        $user = $this->user();
        $user->update(['email_verified_at' => now()]);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->actingAs($user)->patch('/account', ['name' => 'Normal Edit', 'username' => 'normal_edit',
            'email_verified_at' => now()->toDateTimeString()])->assertRedirect('/account');
        $this->assertSame('Normal Edit', $user->fresh()->name);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_signed_guest_invoice_and_provider_webhook_remain_independent_of_the_browser_account(): void
    {
        $user = $this->user();
        $order = $this->order(null, $this->variant());
        $before = $this->financialState();
        $url = URL::temporarySignedRoute('guest.orders.show', now()->addHour(), ['order' => $order->id]);
        $this->actingAs($user)->get($url)->assertOk()->assertSee($order->number);
        $this->postJson('/webhooks/paymongo', [])->assertUnauthorized();
        $this->assertSame($before, $this->financialState());
        Http::assertNothingSent();
    }

    public static function forgedVerificationPosts(): array
    {
        return [['/email/verification-code', false], ['/email/verification-code', true],
            ['/email/verify', false], ['/email/verify', true]];
    }

    #[DataProvider('forgedVerificationPosts')]
    public function test_verification_and_send_forms_reject_cross_site_posts_without_the_matching_session_token(string $path, bool $wrongToken): void
    {
        $user = $this->user();
        $environment = $this->app->environment();
        $this->assertSame('testing', $environment);
        try {
            $this->app['env'] = 'local';
            $payload = ['code' => '123456'];
            if ($wrongToken) {
                $payload['_token'] = 'wrong-session-token';
            }
            $this->actingAs($user)->withSession(['_token' => 'verification-session-token'])
                ->post($path, $payload, ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://untrusted.example'])->assertStatus(419);
            $this->assertNull($user->fresh()->email_verified_at);
            Http::assertNothingSent();
        } finally {
            $this->app['env'] = $environment;
        }
    }
}
