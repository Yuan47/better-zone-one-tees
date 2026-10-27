<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CustomerSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignInPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.demo' => false]);
        Http::preventStrayRequests();
    }

    private function account(string $role, bool $verified = true): User
    {
        return User::factory()->create(['role' => $role, 'password' => 'PortalPassword!2026', 'email_verified_at' => $verified ? now() : null]);
    }

    public static function roles(): array
    {
        return [['customer'], ['staff'], ['admin']];
    }

    public function test_customer_and_workspace_forms_have_distinct_audiences_without_public_staff_registration(): void
    {
        config(['services.google.enabled' => true, 'services.google.client_id' => 'fixture', 'services.google.client_secret' => 'fixture',
            'services.google.redirect' => 'http://localhost/auth/google/callback']);
        $this->get('/login')->assertOk()->assertSee('Customer sign in')->assertSee('Continue with Google')->assertSee('Create an account');
        $this->get('/register')->assertOk()->assertSee('Create your account')->assertSee('Continue with Google');
        $this->get('/admin/login')->assertOk()->assertSee('Staff & owner sign in')->assertSee('created by the store owner')
            ->assertSee('action="http://localhost/admin/login"', false)->assertDontSee('Continue with Google')
            ->assertDontSee('href="http://localhost/register"', false)->assertDontSee('auth/google', false)->assertDontSee('Admin navigation');
        $this->get('/admin/register')->assertNotFound();
        $this->post('/admin/register', ['role' => 'admin'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_local_demo_hints_are_scoped_to_the_portal_and_hidden_in_production(): void
    {
        config(['shop.demo' => true]);
        $this->get('/login')->assertOk()->assertSee('customer@zoneone.test')->assertDontSee('staff@zoneone.test')->assertDontSee('admin@zoneone.test');
        $this->get('/admin/login')->assertOk()->assertSee('staff@zoneone.test')->assertSee('admin@zoneone.test')->assertDontSee('customer@zoneone.test');
        $this->app->detectEnvironment(fn () => 'production');
        foreach (['/login', '/admin/login', '/register'] as $page) {
            $this->get($page)->assertOk()->assertDontSee('Local demo accounts')->assertDontSee('ZoneOneDemo!2026');
        }
    }

    public static function wrongPortals(): array
    {
        return [['staff', '/login'], ['admin', '/login'], ['customer', '/admin/login']];
    }

    #[DataProvider('wrongPortals')]
    public function test_valid_credentials_for_the_other_portal_never_log_in_even_with_injected_role(string $role, string $portal): void
    {
        $user = $this->account($role);
        $this->from($portal)->post($portal, ['email' => $user->email, 'password' => 'PortalPassword!2026',
            'role' => $portal === '/login' ? 'customer' : 'admin', 'guard' => 'web', 'workspace' => $portal === '/login'])
            ->assertRedirect($portal)->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
        $this->assertDatabaseMissing('activity_logs', ['action' => 'auth.login']);
        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_correct_portal_rotates_the_session_and_preserves_existing_customer_protection(string $role): void
    {
        $user = $this->account($role);
        $portal = $role === 'customer' ? '/login' : '/admin/login';
        $this->get($portal)->assertOk();
        $beforeId = session()->getId();
        $beforeToken = session()->token();
        $this->withSession(['safe_marker' => 'kept'])->post($portal, ['email' => $user->email, 'password' => 'PortalPassword!2026'])
            ->assertRedirect($role === 'customer' ? '/' : '/admin')->assertSessionHas('safe_marker', 'kept');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($beforeId, session()->getId());
        $this->assertNotSame($beforeToken, session()->token());
        $this->assertSame($role === 'customer', session()->has(CustomerSessions::SESSION_KEY));
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'actor_id' => $user->id, 'actor_role' => $role]);
        Http::assertNothingSent();
    }

    public static function privateResources(): array
    {
        return [['GET', '/admin', '/admin/login'], ['GET', '/admin/orders', '/admin/login'], ['GET', '/admin/products', '/admin/login'],
            ['POST', '/admin/staff', '/admin/login'], ['GET', '/dashboard', '/login'], ['GET', '/account', '/login'],
            ['GET', '/orders', '/login'], ['GET', '/checkout', '/login']];
    }

    #[DataProvider('privateResources')]
    public function test_private_resources_choose_the_right_sign_in_page(string $method, string $url, string $portal): void
    {
        $this->call($method, $url)->assertRedirect($portal);
        $this->assertGuest();
        Http::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_existing_login_uses_its_own_home_and_logout_destination(string $role): void
    {
        $user = $this->account($role);
        $portal = $role === 'customer' ? '/login' : '/admin/login';
        $this->actingAs($user)->get($portal)->assertRedirect($role === 'customer' ? '/' : '/admin');
        $this->post('/logout')->assertRedirect($role === 'customer' ? '/' : '/admin/login');
        $this->assertGuest();
    }

    #[DataProvider('wrongPortals')]
    public function test_logged_in_other_role_gets_switch_guidance_and_cannot_be_replaced_by_posted_credentials(string $role, string $portal): void
    {
        $current = $this->account($role);
        $other = $this->account($portal === '/login' ? 'customer' : 'staff');
        $this->actingAs($current)->get($portal)->assertOk()->assertSee('Sign out to switch accounts')
            ->assertDontSee('name="password"', false)->assertDontSee('Continue with Google');
        $this->from($portal)->post($portal, ['email' => $other->email, 'password' => 'PortalPassword!2026'])
            ->assertRedirect($portal)->assertSessionHasErrors('email');
        $this->assertAuthenticatedAs($current);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'auth.login', 'actor_id' => $other->id]);
    }

    public static function intendedPaths(): array
    {
        return [['customer', '/account?tab=profile', '/account?tab=profile'],
            ['customer', 'http://localhost/orders', 'http://localhost/orders'], ['staff', '/admin/orders?status=ready', '/admin/orders?status=ready'],
            ['admin', '/admin/products', '/admin/products'], ['staff', '/account', '/account'],
            ['staff', '/admin/products', '/admin'], ['customer', '/admin/orders', '/'], ['admin', '/dashboard', '/admin'],
            ['customer', 'https://untrusted.example/steal', '/'], ['staff', '//untrusted.example/steal', '/admin'],
            ['customer', '/\\untrusted.example/steal', '/'], ['admin', '/admin/login', '/admin'],
            ['customer', '/email/verify', '/'], ['staff', '/forgot-password', '/admin'],
            ['customer', 'http://localhost:8123/account', '/'], ['admin', 'https://localhost/admin', '/admin'],
            ['customer', '/%2f%2funtrusted.example', '/'], ['staff', '/admin/../login', '/admin'],
            ['customer', '/account%0d%0aLocation:evil', '/']];
    }

    #[DataProvider('intendedPaths')]
    public function test_intended_destinations_are_local_get_routes_appropriate_to_the_saved_role(string $role, string $target, string $expected): void
    {
        $user = $this->account($role);
        $this->withSession(['url.intended' => $target])->post($role === 'customer' ? '/login' : '/admin/login',
            ['email' => $user->email, 'password' => 'PortalPassword!2026'])->assertRedirect($expected)->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($user);
    }

    public function test_customer_remains_forbidden_from_workspace_after_customer_login(): void
    {
        $user = $this->account('customer');
        $this->post('/login', ['email' => $user->email, 'password' => 'PortalPassword!2026'])->assertRedirect('/');
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/staff')->assertForbidden();
    }

    public function test_workspace_password_change_in_another_session_returns_to_workspace_sign_in(): void
    {
        $user = $this->account('staff');
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'PortalPassword!2026'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
        $user->update(['password' => 'ReplacementPassword!2026']);
        Auth::forgetGuards();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_portals_share_the_existing_failed_login_throttle(): void
    {
        $user = $this->account('staff');
        for ($index = 0; $index < 5; $index++) {
            $this->from('/admin/login')->post('/admin/login', ['email' => $user->email, 'password' => 'WrongPassword!2026'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'PortalPassword!2026'])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_unverified_workspace_login_preserves_the_intended_get_for_verification(): void
    {
        config(['auth.email_verification.required' => true]);
        $user = $this->account('staff', false);
        $this->get('/admin/orders?status=ready')->assertRedirect('/admin/login');
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'PortalPassword!2026'])
            ->assertRedirect('/email/verify')->assertSessionHas('email_verification.return_to', 'http://localhost/admin/orders?status=ready')
            ->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($user);
        $this->get('/admin')->assertRedirect('/email/verify');
        Http::assertNothingSent();
    }

    public function test_unverified_other_portal_session_can_switch_but_cannot_enter_the_workspace(): void
    {
        config(['auth.email_verification.required' => true]);
        $user = $this->account('customer', false);
        $this->actingAs($user)->get('/admin/login')->assertOk()->assertSee('Sign out to switch accounts');
        $this->get('/admin')->assertRedirect('/email/verify');
        $this->post('/logout', ['sign_in_portal' => 'workspace'])->assertRedirect('/admin/login');
        $this->assertGuest();
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'PortalPassword!2026'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_destination_hint_only_selects_known_internal_sign_in_pages(): void
    {
        $user = $this->account('customer');
        $this->actingAs($user)->post('/logout', ['sign_in_portal' => 'https://untrusted.example'])->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_shared_recovery_links_offer_both_sign_in_pages_without_looking_up_an_account(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('http://localhost/admin/login', false)->assertSee('Customer sign in');
        $this->get('/reset-password/'.str_repeat('a', 64))->assertOk()->assertSee('http://localhost/admin/login', false)->assertSee('Customer sign in');
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }
}
