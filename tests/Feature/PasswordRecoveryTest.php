<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\EmailVerificationCode;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\EmailVerificationCodes;
use App\Services\OrderFlow;
use App\Services\PasswordRecovery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private array $links = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-16 16:00:00', 'Asia/Manila'));
        config(['auth.password_recovery.enabled' => true, 'app.url' => 'https://shop.zoneone.example',
            'services.brevo.api_key' => 'mock-recovery-key', 'services.brevo.from_email' => 'accounts@zoneone.example',
            'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function acceptEmail(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function ($request) {
            preg_match('~https://[^\s]+/reset-password/([a-f0-9]{64})\?email=([^\s]+)~', $request['textContent'], $matches);
            $this->assertNotEmpty($matches);
            $this->links[] = ['token' => $matches[1], 'email' => rawurldecode($matches[2]), 'url' => $matches[0]];

            return Http::response(['messageId' => '<recovery-'.count($this->links).'@brevo.example>'], 201);
        }]);
    }

    private function credentials(User $user, string $token, string $password = 'RecoveredPassword!2026'): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['orders', 'order_items', 'variants', 'payment_attempts', 'refunds', 'order_reviews'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function financialFixture(User $user): void
    {
        $product = Product::create(['name' => 'Recovery fixture tee', 'slug' => 'recovery-fixture-tee', 'category' => '', 'description' => 'Recovery isolation fixture']);
        $variant = $product->variants()->create(['sku' => 'RECOVERY-M-WHITE', 'size' => 'M', 'color' => 'White',
            'stock' => 20, 'retail_price' => 25000, 'wholesale_price' => 20000, 'reseller_price' => 18000]);
        app(OrderFlow::class)->reserve([$variant->id => 5], ['recipient' => 'Recovery buyer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0], $user->id);
    }

    public function test_request_uses_framework_hashed_token_and_configured_url_instead_of_request_host(): void
    {
        $user = User::factory()->create(['name' => 'Recovery Customer', 'email' => 'recovery@example.test']);
        $this->acceptEmail();
        $this->withHeader('Host', 'attacker.example')->post('/forgot-password', ['email' => strtoupper($user->email)])
            ->assertRedirect(route('password.request'))->assertSessionHas('notice', PasswordRecovery::NOTICE);
        $link = $this->links[0];
        $this->assertSame($user->email, $link['email']);
        $this->assertStringStartsWith('https://shop.zoneone.example/reset-password/', $link['url']);
        $stored = DB::table('password_reset_tokens')->sole();
        $this->assertNotSame($link['token'], $stored->token);
        $this->assertTrue(Hash::check($link['token'], $stored->token));
        $this->assertTrue(Password::broker('users')->tokenExists($user, $link['token']));
        $this->assertSame(60, config('auth.passwords.users.expire'));
        $this->assertSame(60, config('auth.passwords.users.throttle'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->header('api-key') === ['mock-recovery-key']
            && $request['sender']['email'] === 'accounts@zoneone.example' && $request['to'][0]['email'] === $user->email
            && $request['tags'] === ['account-password-recovery'] && Str::isUuid($request['headers']['IdempotencyKey'])
            && str_contains($request['htmlContent'], 'Choose a new password') && ! str_contains($request['htmlContent'], 'attacker.example'));
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertGuest();
    }

    public function test_known_unknown_and_throttled_emails_have_the_same_public_notice(): void
    {
        $user = User::factory()->create();
        $this->acceptEmail();
        foreach ([$user->email, 'unknown@example.test', $user->email] as $email) {
            $this->post('/forgot-password', ['email' => $email])->assertRedirect(route('password.request'))
                ->assertSessionHas('notice', PasswordRecovery::NOTICE)->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('password_reset_tokens', 1);
        Http::assertSentCount(1);
        $this->assertGuest();
    }

    public static function unavailableSettings(): array
    {
        return [
            ['auth.password_recovery.enabled', false], ['auth.password_recovery.enabled', 'true'],
            ['services.brevo.api_key', null], ['services.brevo.from_email', 'invalid'],
            ['services.brevo.from_name', ''], ['app.url', null], ['app.url', '/relative'],
            ['app.url', 'javascript:alert(1)'], ['app.url', 'https://name:secret@shop.example'],
            ['app.url', 'https://shop.example?email=evil'], ['app.url', 'https://shop.example/#fragment'],
            ['app.url', "https://shop.example\r\nInjected: true"],
        ];
    }

    #[DataProvider('unavailableSettings')]
    public function test_unavailable_recovery_creates_no_token_or_provider_request(string $setting, mixed $value): void
    {
        $user = User::factory()->create();
        config([$setting => $value]);
        $this->assertFalse(app(PasswordRecovery::class)->ready());
        $this->get('/forgot-password')->assertOk()->assertSee('Password recovery email is not available yet.');
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect(route('password.request'))
            ->assertSessionHas('notice', PasswordRecovery::NOTICE);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Http::assertNothingSent();
    }

    public function test_resend_wait_is_persistent_and_new_link_replaces_the_old_one(): void
    {
        $user = User::factory()->create();
        $this->acceptEmail();
        app(PasswordRecovery::class)->request($user->email);
        $first = $this->links[0]['token'];
        $before = (array) DB::table('password_reset_tokens')->sole();
        $this->travel(59)->seconds();
        (new PasswordRecovery)->request($user->email);
        $this->assertSame($before, (array) DB::table('password_reset_tokens')->sole());
        Http::assertSentCount(1);
        $this->travel(1)->second();
        (new PasswordRecovery)->request($user->email);
        $second = $this->links[1]['token'];
        $this->assertNotSame($first, $second);
        $this->assertFalse(app(PasswordRecovery::class)->reset($this->credentials($user, $first)));
        $this->assertTrue(app(PasswordRecovery::class)->reset($this->credentials($user, $second)));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Http::assertSentCount(2);
    }

    public static function providerFailures(): array
    {
        return [[400], [401], [422], [429], [301], [500], [503], [200], [201]];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_never_changes_public_response_or_retries_automatically(int $status): void
    {
        $user = User::factory()->create();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['message' => 'Private provider failure'], $status)]);
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect(route('password.request'))
            ->assertSessionHas('notice', PasswordRecovery::NOTICE);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('notice', PasswordRecovery::NOTICE);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_provider_timeout_keeps_one_usable_link_without_automatic_resend(): void
    {
        $user = User::factory()->create();
        $token = null;
        $calls = 0;
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function ($request) use (&$token, &$calls) {
            $calls++;
            preg_match('~/reset-password/([a-f0-9]{64})\?~', $request['textContent'], $matches);
            $token = $matches[1];
            throw new ConnectionException('Simulated timeout with private provider details');
        }]);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('notice', PasswordRecovery::NOTICE);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('notice', PasswordRecovery::NOTICE);
        $this->assertSame(1, $calls);
        $this->assertTrue(app(PasswordRecovery::class)->reset($this->credentials($user, $token)));
        $this->assertTrue(Hash::check('RecoveredPassword!2026', $user->fresh()->password));
    }

    public static function roles(): array
    {
        return [['customer'], ['staff'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_reset_is_single_use_revokes_sessions_and_remember_token_and_does_not_verify_email_or_touch_financial_records(string $role): void
    {
        $user = User::factory()->unverified()->create(['role' => $role, 'password' => 'OriginalPassword!2026', 'remember_token' => 'old-remember-token']);
        $other = User::factory()->create();
        $this->financialFixture($user);
        $before = $this->snapshot();
        $originalAccount = $user->only(['name', 'email', 'role', 'email_verified_at', 'phone']);
        config(['auth.email_verification.required' => true]);
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<otp@brevo.example>'], 201)]);
        app(EmailVerificationCodes::class)->send($user);
        $this->assertNotNull(EmailVerificationCode::sole()->code_hash);
        $this->acceptEmail();
        foreach ([$user->id, $user->id, $other->id] as $index => $id) {
            DB::table('sessions')->insert(['id' => 'recovery-session-'.$index, 'user_id' => $id, 'payload' => 'fixture', 'last_activity' => now()->timestamp]);
        }
        app(PasswordRecovery::class)->request($user->email);
        $token = $this->links[0]['token'];
        $this->post('/reset-password', $this->credentials($user, $token))->assertRedirect($role === 'customer' ? '/login' : '/admin/login')
            ->assertSessionHas('notice', 'Your password has been reset. Sign in with your new password.');
        $after = $user->fresh();
        $this->assertTrue(Hash::check('RecoveredPassword!2026', $after->password));
        $this->assertFalse(Hash::check('OriginalPassword!2026', $after->password));
        $this->assertNotSame('old-remember-token', $after->remember_token);
        $this->assertSame($originalAccount, $after->only(array_keys($originalAccount)));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'recovery-session-2', 'user_id' => $other->id]);
        $this->assertSame('invalidated', EmailVerificationCode::sole()->status);
        $this->assertNull(EmailVerificationCode::sole()->code_hash);
        $this->assertNotNull(EmailVerificationCode::sole()->consumed_at);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame($before, $this->snapshot());
        $log = ActivityLog::where('action', 'auth.password_reset')->sole();
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->id, $log->target_id);
        $this->assertSame([], $log->metadata);
        $this->assertStringNotContainsString($token, json_encode($log->toArray()));
        $this->assertStringNotContainsString('RecoveredPassword!2026', json_encode($log->toArray()));
        $this->post('/reset-password', $this->credentials($user, $token, 'ReplayPassword!2026'))->assertStatus(422)->assertSee(PasswordRecovery::INVALID_LINK);
        $this->assertTrue(Hash::check('RecoveredPassword!2026', $user->fresh()->password));
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertGuest();
    }

    public function test_unknown_email_wrong_token_cross_account_and_expired_link_have_the_same_failure_without_changes(): void
    {
        $user = User::factory()->create(['password' => 'OriginalPassword!2026']);
        $other = User::factory()->create(['password' => 'OtherPassword!2026']);
        $token = Password::broker('users')->createToken($user);
        $original = $user->password;
        $otherOriginal = $other->password;
        foreach ([['email' => 'unknown@example.test', 'token' => $token], ['email' => $user->email, 'token' => str_repeat('f', 64)],
            ['email' => $other->email, 'token' => $token]] as $case) {
            $this->post('/reset-password', $case + ['password' => 'RecoveredPassword!2026', 'password_confirmation' => 'RecoveredPassword!2026'])
                ->assertStatus(422)->assertSee(PasswordRecovery::INVALID_LINK)->assertSessionMissing('_old_input')->assertSessionMissing('errors');
        }
        $this->travel(60)->minutes();
        $this->travel(1)->second();
        $this->post('/reset-password', $this->credentials($user, $token))->assertStatus(422)->assertSee(PasswordRecovery::INVALID_LINK);
        $this->assertSame($original, $user->fresh()->password);
        $this->assertSame($otherOriginal, $other->fresh()->password);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public static function invalidCredentials(): array
    {
        return [
            [['token' => 'bad-token']], [['token' => ['array-token']]], [['token' => '']],
            [['email' => 'not-an-email']], [['email' => ['array-email']]],
            [['password' => 'short', 'password_confirmation' => 'short']],
            [['password' => 'RecoveredPassword!2026', 'password_confirmation' => 'MismatchPassword!2026']],
            [['password' => str_repeat('a', 256), 'password_confirmation' => str_repeat('a', 256)]],
            'bcrypt ASCII limit' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)]],
            'bcrypt Unicode byte limit' => [['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)]],
            'password null byte' => [['password' => "LongPassword\0!2026", 'password_confirmation' => "LongPassword\0!2026"]],
        ];
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_reset_input_never_flashes_secrets_or_consumes_a_valid_token(array $overrides): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        $original = $user->password;
        $response = $this->post('/reset-password', array_replace($this->credentials($user, $token), $overrides));
        $response->assertStatus(422)->assertSessionMissing('_old_input')->assertSessionMissing('errors');
        $this->assertStringNotContainsString('value="RecoveredPassword!2026"', $response->getContent());
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        $this->assertSame($original, $user->fresh()->password);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_recovery_does_not_consume_an_existing_link_or_change_password(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        $original = $user->password;
        config(['auth.password_recovery.enabled' => false]);
        $this->post('/reset-password', $this->credentials($user, $token))->assertStatus(422)->assertSee(PasswordRecovery::INVALID_LINK);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        $this->assertSame($original, $user->fresh()->password);
        Http::assertNothingSent();
    }

    public function test_an_existing_link_still_works_during_email_provider_outage_until_its_original_expiry(): void
    {
        $user = User::factory()->create(['email' => 'registered@example.test']);
        $this->acceptEmail();
        app(PasswordRecovery::class)->request($user->email);
        $token = $this->links[0]['token'];
        $this->travel(59)->minutes();
        $this->travel(59)->seconds();
        config(['services.brevo.api_key' => null]);
        $this->assertFalse(app(PasswordRecovery::class)->ready());
        $credentials = $this->credentials($user, $token);
        $credentials['email'] = strtoupper($user->email);
        $this->post('/reset-password', $credentials)->assertRedirect('/login');
        $this->assertTrue(Hash::check('RecoveredPassword!2026', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Http::assertSentCount(1);
        $this->assertGuest();
    }

    public function test_invalid_link_messages_do_not_reveal_whether_an_email_is_registered(): void
    {
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@example.test', 'invalid-email'] as $email) {
            $this->post('/reset-password', ['email' => $email, 'token' => 'malformed',
                'password' => 'RecoveredPassword!2026', 'password_confirmation' => 'RecoveredPassword!2026'])
                ->assertStatus(422)->assertSee(PasswordRecovery::INVALID_LINK)->assertSessionMissing('_old_input');
        }
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_audit_failure_rolls_back_password_token_session_and_otp_changes(): void
    {
        $user = User::factory()->unverified()->create(['remember_token' => 'existing-remember-token']);
        DB::table('sessions')->insert(['id' => 'audit-rollback-session', 'user_id' => $user->id, 'payload' => 'fixture', 'last_activity' => now()->timestamp]);
        config(['auth.email_verification.required' => true]);
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<otp@brevo.example>'], 201)]);
        app(EmailVerificationCodes::class)->send($user);
        $token = Password::broker('users')->createToken($user);
        $original = $user->password;
        $otp = EmailVerificationCode::sole()->getRawOriginal();
        $this->mock(ActivityRecorder::class)->shouldReceive('record')->once()->andReturnNull();
        try {
            app(PasswordRecovery::class)->reset($this->credentials($user, $token));
            $this->fail('The failed audit must abort the password reset.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The password change could not be recorded.', $exception->getMessage());
        }
        $this->assertSame($original, $user->fresh()->password);
        $this->assertSame('existing-remember-token', $user->fresh()->remember_token);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        $this->assertDatabaseHas('sessions', ['id' => 'audit-rollback-session']);
        $this->assertSame($otp, EmailVerificationCode::sole()->getRawOriginal());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_recovery_pages_have_no_store_and_no_referrer_headers_and_do_not_send_email_on_get(): void
    {
        foreach (['/forgot-password', '/reset-password/'.str_repeat('a', 64).'?email=account%40example.test', '/reset-password/malformed?email%5B%5D=bad'] as $url) {
            $response = $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Http::assertNothingSent();
    }

    public function test_request_and_reset_require_real_csrf_before_any_state_or_email_work(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'local';
        try {
            $this->assertFalse($this->app->runningUnitTests());
            foreach (['/forgot-password' => ['email' => $user->email], '/reset-password' => $this->credentials($user, $token)] as $url => $payload) {
                $this->withSession(['_token' => 'correct-session-token'])->post($url, $payload,
                    ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://untrusted.example'])->assertStatus(419);
                $this->withSession(['_token' => 'correct-session-token'])->post($url, $payload + ['_token' => 'wrong-token'],
                    ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://untrusted.example'])->assertStatus(419);
            }
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        Http::assertNothingSent();
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_request_ip_limit_applies_to_unknown_addresses_without_sending_mail(): void
    {
        for ($request = 0; $request < 5; $request++) {
            $this->post('/forgot-password', ['email' => 'unknown-'.$request.'@example.test'])->assertRedirect(route('password.request'));
        }
        $this->post('/forgot-password', ['email' => 'unknown-final@example.test'])->assertStatus(429);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Http::assertNothingSent();
    }
}
