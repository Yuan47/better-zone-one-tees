<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\GoogleIdentity;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\GoogleOAuthProvider;
use App\Services\GoogleSignIn;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private MockHandler $http;

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['app.url' => 'http://localhost', 'services.google.enabled' => true,
            'services.google.client_id' => 'isolated-google-client.apps.googleusercontent.com',
            'services.google.client_secret' => 'isolated-google-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback']);
        Http::preventStrayRequests();
        // Socialite uses Guzzle directly. Mock its actual transport, not just Laravel's HTTP facade.
        $this->http = new MockHandler;
        $handler = HandlerStack::create($this->http);
        $handler->push(Middleware::history($this->requests));
        $client = new Client(['handler' => $handler, 'allow_redirects' => false]);
        $this->app->instance(GoogleSignIn::class, new class($client) extends GoogleSignIn
        {
            public function __construct(private Client $http) {}

            protected function provider(Request $request): GoogleOAuthProvider
            {
                return parent::provider($request)->setHttpClient($this->http);
            }
        });
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function profile(array $overrides = []): array
    {
        return array_replace(['sub' => 'google-fixture-10001', 'email' => 'google.customer@example.test',
            'email_verified' => true, 'name' => 'Google Customer', 'picture' => 'http://127.0.0.1/private-image'], $overrides);
    }

    private function providerResponds(array $profile = [], ?array $token = null): void
    {
        $this->http->append(new Response(200, ['Content-Type' => 'application/json'], json_encode($token ?? [
            'access_token' => 'isolated-access-token', 'token_type' => 'Bearer', 'expires_in' => 3599,
            'scope' => 'openid email profile', 'refresh_token' => 'must-not-be-stored', 'id_token' => 'not-used-for-identity',
        ])), new Response(200, ['Content-Type' => 'application/json'], json_encode($this->profile($profile))));
    }

    private function begin(): array
    {
        $response = $this->post(route('google.start'))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->withCookie(config('session.cookie'), session()->getId());

        return $parameters;
    }

    private function completeCallback(string $state, array $parameters = [])
    {
        return $this->get(route('google.callback', array_replace(['state' => $state, 'code' => 'isolated-code'], $parameters)));
    }

    private function customer(array $overrides = []): User
    {
        return User::factory()->create(array_replace(['role' => 'customer', 'email' => 'google.customer@example.test',
            'email_verified_at' => now(), 'password' => 'ExistingPassword!2026'], $overrides));
    }

    private function beginLink(User $user): array
    {
        $response = $this->actingAs($user)->post(route('google.link'), ['current_password' => 'ExistingPassword!2026'])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->withCookie(config('session.cookie'), session()->getId());

        return $parameters;
    }

    public function test_configured_customer_buttons_appear_and_oauth_uses_state_pkce_and_minimal_scopes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Continue with Google')->assertSee('Staff and owners sign in with email and password.');
        $this->get('/register')->assertOk()->assertSee('Continue with Google');
        $parameters = $this->begin();
        $this->assertSame('openid email profile', $parameters['scope']);
        $this->assertSame('code', $parameters['response_type']);
        $this->assertSame('S256', $parameters['code_challenge_method']);
        $this->assertSame(40, strlen($parameters['state']));
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', session('code_verifier'), true)), '+/', '-_'), '='), $parameters['code_challenge']);
        $this->assertSame(now()->addMinutes(10)->timestamp, session('google_oauth.expires_at'));
        $this->assertSame([], $this->requests);
    }

    public static function invalidConfiguration(): array
    {
        return [
            [['services.google.enabled' => false]], [['services.google.client_id' => '']], [['services.google.client_secret' => null]],
            [['services.google.redirect' => '']], [['services.google.redirect' => 'https://other.example/auth/google/callback']],
            [['services.google.redirect' => 'http://localhost/auth/google/callback#fragment']],
            [['services.google.redirect' => 'http://localhost/auth/google/callback?next=outside']],
            [['services.google.redirect' => 'http://user@localhost/auth/google/callback']],
            [['app.url' => 'http://store.example', 'services.google.redirect' => 'http://store.example/auth/google/callback']],
            [['services.google.redirect' => 'http://localhost/wrong-callback']],
        ];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_incomplete_or_unsafe_configuration_hides_buttons_and_fails_closed(array $config): void
    {
        config($config);
        $this->get('/login')->assertOk()->assertDontSee('Continue with Google');
        $this->post(route('google.start'))->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->get(route('google.callback', ['code' => 'ignored', 'state' => 'ignored']))->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseCount('google_identities', 0);
        $this->assertSame([], $this->requests);
    }

    public function test_new_verified_google_customer_gets_secure_normal_session_without_storing_provider_secrets_or_photo(): void
    {
        config(['auth.email_verification.required' => true]);
        $parameters = $this->begin();
        $oldSession = session()->getId();
        $verifier = session('code_verifier');
        $this->withSession(['url.intended' => 'https://untrusted.example/redirect']);
        $this->providerResponds(['email' => 'GOOGLE.Customer@example.test']);
        $this->completeCallback($parameters['state'], ['next' => 'https://untrusted.example'])->assertRedirect('/')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertSessionMissing('state')->assertSessionMissing('google_oauth')->assertSessionMissing('code_verifier');
        $user = User::where('email', 'google.customer@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('customer', $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->avatar_path);
        $this->assertSame('Google Customer', $user->name);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertSame($user->id, session('customer_idle.user_id'));
        $this->assertSame(now()->addMinutes(15)->timestamp, session('customer_idle.expires_at'));
        $this->assertNotEmpty(session('password_hash_web'));
        $this->assertDatabaseHas('google_identities', ['user_id' => $user->id, 'subject' => 'google-fixture-10001']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.registered', 'actor_id' => $user->id]);
        $this->assertCount(2, $this->requests);
        $tokenRequest = $this->requests[0]['request'];
        $this->assertSame('https://www.googleapis.com/oauth2/v4/token', (string) $tokenRequest->getUri());
        parse_str((string) $tokenRequest->getBody(), $tokenFields);
        $this->assertSame($verifier, $tokenFields['code_verifier']);
        $this->assertSame('isolated-google-secret', $tokenFields['client_secret']);
        $this->assertSame('Bearer isolated-access-token', $this->requests[1]['request']->getHeaderLine('Authorization'));
        $serialized = json_encode(['user' => $user->getAttributes(), 'identity' => GoogleIdentity::first()->getAttributes(),
            'session' => session()->all(), 'logs' => ActivityLog::all()->toArray()]);
        foreach (['isolated-access-token', 'isolated-google-secret', 'must-not-be-stored', 'isolated-code', 'not-used-for-identity'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        Auth::forgetGuards();
        $this->get('/account')->assertOk()->assertSee('Google linked');
        $this->get('/checkout')->assertRedirect('/cart');
    }

    public function test_linked_google_identity_signs_into_same_customer_without_replacing_profile_or_email(): void
    {
        $user = $this->customer(['name' => 'Keep My Profile']);
        GoogleIdentity::create(['user_id' => $user->id, 'subject' => 'google-fixture-10001']);
        $parameters = $this->begin();
        $this->providerResponds(['name' => 'Different Google Name']);
        $this->completeCallback($parameters['state'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Keep My Profile', $user->fresh()->name);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'actor_id' => $user->id]);
    }

    public static function existingRoles(): array
    {
        return [['customer'], ['staff'], ['admin']];
    }

    #[DataProvider('existingRoles')]
    public function test_matching_existing_email_never_silently_links_or_changes_role(string $role): void
    {
        $user = $this->customer(['role' => $role, 'email' => 'Google.Customer@Example.Test', 'email_verified_at' => null]);
        $before = $user->fresh()->getAttributes();
        $parameters = $this->begin();
        $this->providerResponds();
        $this->completeCallback($parameters['state'])->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('google_identities', 0);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_pending_staff_setup_cannot_be_bypassed_by_google_customer_registration(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        StaffInvitation::create(['name' => 'Pending Staff', 'email' => 'Google.Customer@Example.Test',
            'created_by' => $owner->id, 'password_hash' => Hash::make('PendingPassword!2026')]);
        $parameters = $this->begin();
        $this->providerResponds();
        $this->completeCallback($parameters['state'])->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('google_identities', 0);
        $this->assertDatabaseHas('staff_invitations', ['status' => 'draft', 'consumed_at' => null]);
    }

    public function test_authenticated_password_confirmation_links_customer_then_google_login_uses_that_identity(): void
    {
        $user = $this->customer();
        $parameters = $this->beginLink($user);
        $this->providerResponds();
        $this->completeCallback($parameters['state'])->assertRedirect('/account')->assertSessionHas('notice');
        $this->assertDatabaseHas('google_identities', ['user_id' => $user->id, 'subject' => 'google-fixture-10001']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.google_linked', 'actor_id' => $user->id]);
        $this->assertTrue(Hash::check('ExistingPassword!2026', $user->fresh()->password));
        $this->post('/logout')->assertRedirect('/');
        $parameters = $this->begin();
        $this->providerResponds();
        $this->completeCallback($parameters['state'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_link_requires_current_password_and_authenticated_customer(): void
    {
        $this->post(route('google.link'), ['current_password' => 'ExistingPassword!2026'])->assertRedirect('/login');
        $customer = $this->customer();
        $this->actingAs($customer)->from('/account')->post(route('google.link'))->assertRedirect('/account')->assertSessionHasErrors('current_password');
        $this->post(route('google.link'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        foreach (['staff', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/account')->assertOk()->assertDontSee('Link Google account');
            $this->post(route('google.link'), ['current_password' => 'anything'])->assertForbidden();
        }
        $this->assertDatabaseCount('google_identities', 0);
        $this->assertSame([], $this->requests);
    }

    public static function invalidProfiles(): array
    {
        return [
            [['email_verified' => false]], [['email_verified' => 'true']], [['email_verified' => null]],
            [['sub' => '']], [['sub' => null]], [['sub' => 10001]], [['sub' => str_repeat('a', 256)]], [['sub' => 'invalid subject']],
            [['email' => 'bad-email']], [['email' => null]], [['email' => ['email@example.test']]],
            [['email' => "google.customer@example.test\r\nInjected: value"]],
        ];
    }

    #[DataProvider('invalidProfiles')]
    public function test_unverified_or_malformed_provider_identity_cannot_create_accounts(array $profile): void
    {
        $parameters = $this->begin();
        $this->providerResponds($profile);
        $this->completeCallback($parameters['state'])->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('google_identities', 0);
        $this->assertNull(session('google_oauth'));
    }

    public static function invalidTokens(): array
    {
        return [
            [[]], [['access_token' => null]], [['access_token' => []]], [['access_token' => '']],
            [['access_token' => 'token', 'token_type' => 'Basic', 'expires_in' => 3600]],
            [['access_token' => 'token', 'token_type' => [], 'expires_in' => 3600]],
            [['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 0]],
            [['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => '3600']],
            [['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 3600, 'error' => 'invalid_grant']],
        ];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_token_exchange_fails_without_requesting_userinfo(array $token): void
    {
        $parameters = $this->begin();
        $this->providerResponds([], $token);
        $this->completeCallback($parameters['state'])->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertCount(1, $this->requests);
        $this->assertDatabaseCount('users', 0);
    }

    public static function providerFailures(): array
    {
        return [['token-timeout'], ['token-400'], ['token-json'], ['userinfo-timeout'], ['userinfo-401'], ['userinfo-json']];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_cancellation_or_outage_is_safe_and_does_not_expose_payloads(string $failure): void
    {
        $parameters = $this->begin();
        if (str_starts_with($failure, 'userinfo')) {
            $this->http->append(new Response(200, [], json_encode(['access_token' => 'isolated-token', 'token_type' => 'Bearer', 'expires_in' => 3600])));
        }
        $this->http->append(match (true) {
            str_ends_with($failure, 'timeout') => new ConnectException('sensitive-provider-detail', new GuzzleRequest('POST', 'https://www.googleapis.com')),
            str_ends_with($failure, 'json') => new Response(200, [], 'not-json sensitive-provider-detail'),
            default => new Response(401, [], '{"error":"sensitive-provider-detail"}'),
        });
        $this->completeCallback($parameters['state'])->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertStringNotContainsString('sensitive-provider-detail', json_encode(session()->all()));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(session('state'));
    }

    public function test_cancelled_google_consent_consumes_state_and_allows_a_clean_retry(): void
    {
        $parameters = $this->begin();
        $this->completeCallback($parameters['state'], ['error' => 'access_denied'])->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertSame([], $this->requests);
        $this->assertNull(session('state'));
        $retry = $this->begin();
        $this->assertNotSame($parameters['state'], $retry['state']);
        $this->providerResponds();
        $this->completeCallback($retry['state'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_unknown_missing_expired_replayed_and_racing_callback_state_are_rejected_before_http(): void
    {
        $this->get(route('google.callback', ['code' => 'code']))->assertRedirect('/login')->assertSessionHasErrors('google');
        $parameters = $this->begin();
        $this->completeCallback(str_repeat('z', 40))->assertSessionHasErrors('google');
        $parameters = $this->begin();
        $this->travel(10)->minutes();
        $this->completeCallback($parameters['state'])->assertSessionHasErrors('google');
        $this->travelBack();
        $parameters = $this->begin();
        $stale = session()->all();
        $this->completeCallback($parameters['state'], ['error' => 'access_denied'])->assertSessionHasErrors('google');
        // A stale session save from another in-flight request must not revive an already consumed ticket.
        $this->withSession($stale);
        $this->completeCallback($parameters['state'])->assertSessionHasErrors('google');
        $this->assertSame([], $this->requests);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_successful_callback_cannot_be_replayed_even_after_logout_and_stale_session_restore(): void
    {
        $parameters = $this->begin();
        $stale = session()->all();
        $this->providerResponds();
        $this->completeCallback($parameters['state'])->assertRedirect('/');
        $this->post('/logout')->assertRedirect('/');
        $this->withSession($stale);
        $this->completeCallback($parameters['state'])->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('google_identities', 1);
        $this->assertCount(2, $this->requests);
    }

    public function test_session_switch_during_sign_in_or_linking_cannot_change_the_current_account(): void
    {
        $parameters = $this->begin();
        $current = $this->customer();
        $this->actingAs($current);
        $this->completeCallback($parameters['state'])->assertRedirect('/account')->assertSessionHasErrors('google');
        $parameters = $this->beginLink($current);
        $other = User::factory()->create(['role' => 'customer', 'password' => 'OtherPassword!2026']);
        $this->post('/logout')->assertRedirect('/');
        $this->post('/login', ['email' => $other->email, 'password' => 'OtherPassword!2026'])->assertRedirect('/');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->completeCallback($parameters['state'])->assertRedirect('/account')->assertSessionHasErrors('google');
        $this->assertAuthenticatedAs($other);
        $this->assertSame([], $this->requests);
        $this->assertDatabaseCount('google_identities', 0);
    }

    public function test_linking_rejects_google_email_mismatch_and_another_account_owned_subject(): void
    {
        $current = $this->customer();
        $parameters = $this->beginLink($current);
        $this->providerResponds(['email' => 'other@example.test']);
        $this->completeCallback($parameters['state'])->assertRedirect('/account')->assertSessionHasErrors('google');
        $owner = User::factory()->create(['role' => 'customer']);
        GoogleIdentity::create(['user_id' => $owner->id, 'subject' => 'google-fixture-10001']);
        $parameters = $this->beginLink($current);
        $this->providerResponds();
        $this->completeCallback($parameters['state'])->assertRedirect('/account')->assertSessionHasErrors('google');
        $this->assertDatabaseCount('google_identities', 1);
        $this->assertDatabaseHas('google_identities', ['user_id' => $owner->id]);
        $this->assertSame('google.customer@example.test', $current->fresh()->email);
    }

    public function test_linked_subject_with_changed_email_cannot_replace_fixed_store_email(): void
    {
        $user = $this->customer();
        GoogleIdentity::create(['user_id' => $user->id, 'subject' => 'google-fixture-10001']);
        $parameters = $this->begin();
        $this->providerResponds(['email' => 'changed@example.test']);
        $this->completeCallback($parameters['state'])->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertSame('google.customer@example.test', $user->fresh()->email);
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('existingRoles')]
    public function test_google_cannot_sign_into_staff_or_owner_even_with_an_old_link(string $role): void
    {
        if ($role === 'customer') {
            $role = 'staff';
        }
        $user = $this->customer(['role' => $role]);
        GoogleIdentity::create(['user_id' => $user->id, 'subject' => 'google-fixture-10001']);
        $parameters = $this->begin();
        $this->providerResponds();
        $this->completeCallback($parameters['state'])->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertSame($role, $user->fresh()->role);
    }

    public function test_google_session_keeps_existing_password_change_and_idle_logout_safeguards(): void
    {
        $user = $this->customer();
        GoogleIdentity::create(['user_id' => $user->id, 'subject' => 'google-fixture-10001']);
        $parameters = $this->begin();
        $this->providerResponds();
        $this->completeCallback($parameters['state'])->assertRedirect('/');
        $this->travel(15)->minutes();
        Auth::forgetGuards();
        $this->get('/account')->assertRedirect();
        $this->assertGuest();
        $this->travelBack();
        $parameters = $this->begin();
        $this->providerResponds();
        $this->completeCallback($parameters['state'])->assertRedirect('/');
        $user->update(['password' => 'ResetPassword!2026']);
        Auth::forgetGuards();
        $this->get('/account')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_google_start_and_link_require_csrf_and_requests_are_rate_limited(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->withSession(['_token' => 'csrf-google'])->post(route('google.start'))->assertStatus(419);
        $this->actingAs($this->customer())->post(route('google.link'), ['current_password' => 'ExistingPassword!2026'])->assertStatus(419);
        $this->app->detectEnvironment(fn () => 'testing');
        Auth::logout();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('google.start'))->assertRedirect();
        }
        $this->post(route('google.start'))->assertTooManyRequests();
        $this->assertSame([], $this->requests);
    }

    public function test_database_uniqueness_prevents_duplicate_subject_and_multiple_google_links_per_user(): void
    {
        $first = $this->customer();
        $second = User::factory()->create(['role' => 'customer']);
        GoogleIdentity::create(['user_id' => $first->id, 'subject' => 'one-google-subject']);
        $this->assertSame(0, DB::table('google_identities')->insertOrIgnore(['user_id' => $second->id, 'subject' => 'one-google-subject']));
        $this->assertSame(0, DB::table('google_identities')->insertOrIgnore(['user_id' => $first->id, 'subject' => 'another-google-subject']));
        $this->assertDatabaseCount('google_identities', 1);
        $this->assertArrayNotHasKey('subject', GoogleIdentity::first()->toArray());
    }

    public function test_link_password_changed_while_google_is_open_cannot_be_used_to_add_a_new_login(): void
    {
        $user = $this->customer();
        $parameters = $this->beginLink($user);
        // Simulate password recovery in a separate browser while retaining this browser's model instance.
        User::whereKey($user->id)->update(['password' => Hash::make('RecoveredPassword!2026')]);
        $this->providerResponds();
        $this->completeCallback($parameters['state'])->assertRedirect('/account')->assertSessionHasErrors('google');
        $this->assertDatabaseCount('google_identities', 0);
        $this->assertTrue(Hash::check('RecoveredPassword!2026', $user->fresh()->password));
    }

    public function test_callback_limits_and_invalid_query_values_do_not_store_authorization_codes(): void
    {
        $parameters = $this->begin();
        $this->completeCallback($parameters['state'], ['code' => ['invalid-array']])->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertStringNotContainsString('invalid-array', json_encode(session()->all()));
        $this->assertSame([], $this->requests);
        for ($i = 0; $i < 9; $i++) {
            $this->get(route('google.callback', ['state' => 'unknown', 'code' => 'private-authorization-code']))->assertRedirect('/login');
        }
        $this->get(route('google.callback', ['state' => 'unknown', 'code' => 'private-authorization-code']))->assertTooManyRequests();
        $this->assertStringNotContainsString('private-authorization-code', json_encode(session()->all()));
    }
}
