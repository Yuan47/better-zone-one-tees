<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\StaffAccounts;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StaffAccountTest extends TestCase
{
    use RefreshDatabase;

    private array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-16 16:00:00', 'Asia/Manila'));
        config(['auth.staff_setup.enabled' => true, 'auth.email_verification.required' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'services.brevo.api_key' => 'mock-staff-key', 'services.brevo.from_email' => 'accounts@zoneone.example',
            'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function data(array $extra = []): array
    {
        return array_replace(['name' => 'Demo Staff', 'email' => 'new-staff@example.test',
            'password' => 'InitialStaff!2026', 'password_confirmation' => 'InitialStaff!2026'], $extra);
    }

    private function invite(User $admin, array $extra = []): StaffInvitation
    {
        return app(StaffAccounts::class)->create($admin, $this->data($extra));
    }

    private function accepted(?\Closure $during = null): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function ($request) use ($during) {
            $this->assertSame(1, preg_match('/staff setup code is ([0-9]{6})\./', $request['textContent'], $matches));
            $this->codes[] = $matches[1];
            if ($during) {
                $during($request);
            }

            return Http::response(['messageId' => '<staff-'.count($this->codes).'@brevo.example>'], 201);
        }]);
    }

    private function rejected(User $admin, StaffInvitation $invitation, string $code): void
    {
        try {
            app(StaffAccounts::class)->verify($admin, $invitation, $code);
            $this->fail('This setup code must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
    }

    public function test_admin_creates_only_a_pending_setup_and_hashes_initial_password_without_granting_login(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin, ['name' => ' Demo Staff ', 'email' => ' NEW-STAFF@EXAMPLE.TEST ', 'role' => 'admin', 'email_verified_at' => now()]);
        $this->assertSame('Demo Staff', $invitation->name);
        $this->assertSame('new-staff@example.test', $invitation->email);
        $this->assertTrue(Hash::check('InitialStaff!2026', $invitation->password_hash));
        $this->assertSame(1, User::count());
        $this->assertSame('draft', $invitation->status);
        $this->assertNull($invitation->consumed_at);
        $this->assertNull($invitation->activated_user_id);
        $this->assertSame($admin->id, $invitation->created_by);
        $this->assertArrayNotHasKey('password_hash', $invitation->toArray());
        $this->assertStringNotContainsString('InitialStaff!2026', json_encode(DB::table('staff_invitations')->first()));
        $this->assertSame('staff.invited', ActivityLog::sole()->action);
        $this->post('/admin/login', ['email' => $invitation->email, 'password' => 'InitialStaff!2026'])->assertSessionHasErrors('email');
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_code_uses_brevo_persisted_claim_limits_and_no_plaintext_storage(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->accepted(function ($request) use ($invitation) {
            $this->assertSame('claimed', $invitation->fresh()->status);
            $this->assertSame($invitation->email, $request['to'][0]['email']);
        });
        $service = app(StaffAccounts::class);
        $this->assertTrue($service->ready());
        $this->assertSame('sent', $service->send($admin, $invitation));
        $pending = $invitation->fresh();
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $this->codes[0]);
        $this->assertSame(64, strlen($pending->code_hash));
        $this->assertNotSame(hash('sha256', $this->codes[0]), $pending->code_hash);
        $this->assertTrue($pending->expires_at->equalTo(now()->addMinutes(10)));
        $this->assertTrue($pending->resend_at->equalTo(now()->addMinute()));
        $this->assertSame(1, $pending->send_count);
        $this->assertSame(0, $pending->attempts);
        $this->assertSame('sent', $pending->status);
        $this->assertSame('pending', $service->state($pending)['status']);
        $this->assertSame(5, $service->state($pending)['attempts_remaining']);
        $this->assertArrayNotHasKey('code_hash', $pending->toArray());
        $this->assertArrayNotHasKey('nonce', $pending->toArray());
        $this->assertArrayNotHasKey('provider_message_id', $pending->toArray());
        $this->assertStringNotContainsString('"'.$this->codes[0].'"', json_encode(DB::table('staff_invitations')->first()));
        $log = ActivityLog::where('action', 'staff.code_sent')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame([], $log->metadata);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->header('api-key') === ['mock-staff-key']
            && $request['sender']['email'] === 'accounts@zoneone.example'
            && $request['headers']['IdempotencyKey'] === $pending->nonce
            && str_contains($request['htmlContent'], $this->codes[0]) && $request['tags'] === ['staff-account-setup']
            && ! str_contains($request->body(), 'InitialStaff!2026'));
    }

    public function test_correct_code_activates_exactly_one_verified_staff_and_clears_pending_secrets(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $initialHash = $invitation->password_hash;
        $this->accepted();
        $service = app(StaffAccounts::class);
        $service->send($admin, $invitation);
        $staff = $service->verify($admin, $invitation, $this->codes[0]);
        $this->assertSame('staff', $staff->role);
        $this->assertSame($invitation->email, $staff->email);
        $this->assertTrue($staff->email_verified_at->equalTo(now()));
        $this->assertSame($initialHash, $staff->getRawOriginal('password'));
        $this->assertTrue(Hash::check('InitialStaff!2026', $staff->password));
        $pending = $invitation->fresh();
        $this->assertSame('activated', $pending->status);
        $this->assertSame($staff->id, $pending->activated_user_id);
        $this->assertNull($pending->password_hash);
        $this->assertNull($pending->code_hash);
        $this->assertNotNull($pending->consumed_at);
        $this->assertSame('activated', $service->state($pending)['status']);
        $this->rejected($admin, $invitation, $this->codes[0]);
        $this->assertSame('activated', $service->send($admin, $invitation));
        $this->assertSame(1, User::where('role', 'staff')->count());
        $this->assertSame(1, ActivityLog::where('action', 'staff.activated')->count());
        $this->post('/admin/login', ['email' => $staff->email, 'password' => 'InitialStaff!2026'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($staff);
        Http::assertSentCount(1);
    }

    public static function roles(): array
    {
        return [['customer'], ['staff']];
    }

    #[DataProvider('roles')]
    public function test_non_admins_cannot_open_or_mutate_staff_setup(string $role): void
    {
        $admin = $this->admin();
        $pending = $this->invite($admin);
        $member = User::factory()->create(['role' => $role]);
        $this->actingAs($member);
        $this->get('/admin/staff')->assertForbidden();
        $this->get('/admin/staff/create')->assertForbidden();
        $this->get('/admin/staff/'.$pending->id)->assertForbidden();
        $this->post('/admin/staff', $this->data(['email' => 'other@example.test']))->assertForbidden();
        $this->post('/admin/staff/'.$pending->id.'/send')->assertForbidden();
        $this->post('/admin/staff/'.$pending->id.'/verify', ['code' => '123456'])->assertForbidden();
        $this->assertSame(1, StaffInvitation::count());
        $this->assertSame(0, $pending->fresh()->send_count);
        Http::assertNothingSent();
    }

    public function test_all_staff_setup_routes_require_login(): void
    {
        $pending = $this->invite($this->admin());
        foreach (['/admin/staff', '/admin/staff/create', '/admin/staff/'.$pending->id] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
        foreach (['/admin/staff', '/admin/staff/'.$pending->id.'/send', '/admin/staff/'.$pending->id.'/verify'] as $url) {
            $this->post($url, $this->data())->assertRedirect('/admin/login');
        }
        Http::assertNothingSent();
    }

    public function test_service_rechecks_persisted_admin_role_for_every_mutation(): void
    {
        $admin = $this->admin();
        $pending = $this->invite($admin);
        User::whereKey($admin->id)->update(['role' => 'staff']);
        foreach (['create', 'send', 'verify'] as $method) {
            try {
                match ($method) {
                    'create' => app(StaffAccounts::class)->create($admin, $this->data(['email' => 'other@example.test'])),
                    'send' => app(StaffAccounts::class)->send($admin, $pending),
                    'verify' => app(StaffAccounts::class)->verify($admin, $pending, '123456'),
                };
                $this->fail('A demoted admin must be denied.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame(1, StaffInvitation::count());
        Http::assertNothingSent();
    }

    public static function existingRoles(): array
    {
        return [['customer'], ['staff'], ['admin']];
    }

    #[DataProvider('existingRoles')]
    public function test_existing_accounts_cannot_be_reused_or_converted_to_staff(string $role): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create(['email' => 'NEW-STAFF@example.test', 'role' => $role]);
        $this->actingAs($admin)->post('/admin/staff', $this->data())->assertSessionHasErrors('email');
        $this->assertSame($role, $existing->fresh()->role);
        $this->assertDatabaseCount('staff_invitations', 0);
        Http::assertNothingSent();
    }

    public function test_pending_email_is_unique_after_case_and_whitespace_normalization(): void
    {
        $admin = $this->admin();
        $this->invite($admin);
        $this->actingAs($admin)->post('/admin/staff', $this->data(['email' => ' NEW-STAFF@EXAMPLE.TEST ']))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('staff_invitations', 1);
        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    public static function invalidDrafts(): array
    {
        return [
            [['name' => ''], 'name'], [['name' => str_repeat('n', 101)], 'name'], [['name' => ['bad']], 'name'],
            [['email' => 'invalid'], 'email'], [['email' => "hello@example.test\r\nBcc: bad@example.test"], 'email'],
            [['email' => ['bad']], 'email'], [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            [['password_confirmation' => 'DoesNotMatch!2026'], 'password'],
            [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
            [['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)], 'password'],
            [['password' => "Initial\0Staff!2026", 'password_confirmation' => "Initial\0Staff!2026"], 'password'],
        ];
    }

    #[DataProvider('invalidDrafts')]
    public function test_invalid_staff_details_create_nothing_and_do_not_flash_passwords(array $extra, string $field): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/staff', $this->data($extra))->assertRedirect('/admin/staff/create')->assertSessionHasErrors($field)
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseCount('staff_invitations', 0);
        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    public function test_http_flow_is_connected_and_does_not_expose_initial_password_or_code(): void
    {
        $admin = $this->admin();
        $this->accepted();
        $this->actingAs($admin)->get('/admin/staff')->assertOk()->assertSee('Staff accounts')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/admin/staff/create')->assertOk()->assertSee('Confirm initial password');
        $response = $this->post('/admin/staff', $this->data(['role' => 'admin']));
        $invitation = StaffInvitation::sole();
        $response->assertRedirect('/admin/staff/'.$invitation->id);
        $this->get('/admin/staff/'.$invitation->id)->assertOk()->assertSee($invitation->email)->assertSee('Verify email &amp; activate staff', false)
            ->assertDontSee('InitialStaff!2026')->assertDontSee($this->codes[0])->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/admin/staff/'.$invitation->id.'/verify', ['code' => $this->codes[0], 'role' => 'admin'])->assertRedirect('/admin/staff/'.$invitation->id);
        $this->get('/admin/staff/'.$invitation->id)->assertOk()->assertSee('Staff account is ready.')->assertDontSee('name="code"', false);
        $this->assertSame('staff', User::where('email', $invitation->email)->sole()->role);
        $this->get('/admin/staff')->assertOk()->assertSee('new-staff@example.test');
        $logs = ActivityLog::where('action', 'like', 'staff.%')->get();
        $this->assertCount(3, $logs);
        $this->assertStringNotContainsString($this->codes[0], $logs->toJson());
        $this->assertStringNotContainsString('InitialStaff!2026', $logs->toJson());
    }

    public function test_disabled_or_unconfigured_email_keeps_a_private_non_login_draft(): void
    {
        $admin = $this->admin();
        config(['auth.staff_setup.enabled' => false]);
        $response = $this->actingAs($admin)->post('/admin/staff', $this->data());
        $invitation = StaffInvitation::sole();
        $response->assertRedirect('/admin/staff/'.$invitation->id);
        $this->get('/admin/staff/'.$invitation->id)->assertOk()->assertSee('Email delivery is not configured yet.')->assertDontSee('name="code"', false);
        $service = app(StaffAccounts::class);
        $this->assertFalse($service->ready());
        $this->assertSame('unavailable', $service->send($admin, $invitation));
        $this->rejected($admin, $invitation, '123456');
        config(['auth.staff_setup.enabled' => true, 'services.brevo.api_key' => '']);
        $this->assertFalse($service->ready());
        $this->assertSame('unavailable', $service->send($admin, $invitation));
        $this->assertSame(1, User::count());
        Http::assertNothingSent();
    }

    public function test_disabling_staff_setup_after_email_also_blocks_activation(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->accepted();
        $service = app(StaffAccounts::class);
        $service->send($admin, $invitation);
        config(['auth.staff_setup.enabled' => false]);
        $this->rejected($admin, $invitation, $this->codes[0]);
        $this->assertSame(1, User::count());
        $this->assertNull($invitation->fresh()->consumed_at);
    }

    public function test_expiry_at_ten_minutes_is_strict_and_clears_the_challenge(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->accepted();
        $service = app(StaffAccounts::class);
        $service->send($admin, $invitation);
        $this->travel(10)->minutes();
        $this->assertSame('expired', $service->state($invitation)['status']);
        $this->rejected($admin, $invitation, $this->codes[0]);
        $this->assertNull($invitation->fresh()->code_hash);
        $this->assertSame(1, User::count());
    }

    public function test_five_wrong_guesses_lock_the_persisted_code_even_if_the_sixth_is_correct(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->accepted();
        $service = app(StaffAccounts::class);
        $service->send($admin, $invitation);
        $wrong = $this->codes[0] === '000000' ? '000001' : '000000';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->rejected($admin, $invitation, $wrong);
            $this->assertSame($attempt, $invitation->fresh()->attempts);
            $this->assertSame(5 - $attempt, $service->state($invitation)['attempts_remaining']);
        }
        $this->assertSame('locked', $service->state($invitation)['status']);
        $this->rejected($admin, $invitation, $this->codes[0]);
        $this->assertNull($invitation->fresh()->code_hash);
        $this->assertSame(5, $invitation->fresh()->attempts);
        $this->assertSame(1, User::count());
    }

    public static function malformedCodes(): array
    {
        return [[''], ['12345'], ['1234567'], [' 123456'], ['123456 '], ['1e0001'], ['１２３４５６'], ["123456\n"]];
    }

    #[DataProvider('malformedCodes')]
    public function test_malformed_guesses_cannot_activate_and_consume_a_guess(string $code): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->accepted();
        app(StaffAccounts::class)->send($admin, $invitation);
        $this->rejected($admin, $invitation, $code);
        $this->assertSame(1, $invitation->fresh()->attempts);
        $this->assertSame(1, User::count());
    }

    public function test_array_code_is_rejected_without_server_error_or_session_disclosure(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->actingAs($admin)->post('/admin/staff/'.$invitation->id.'/verify', ['code' => ['123456']])
            ->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
        $this->assertSame(1, User::count());
    }

    public function test_incorrect_code_is_not_copied_into_session_old_input(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->accepted();
        app(StaffAccounts::class)->send($admin, $invitation);
        $wrong = $this->codes[0] === '000000' ? '000001' : '000000';
        $this->actingAs($admin)->post('/admin/staff/'.$invitation->id.'/verify', ['code' => $wrong])
            ->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
        $this->assertSame(1, $invitation->fresh()->attempts);
        $this->assertSame(1, User::count());
    }

    public function test_missing_application_secret_blocks_sending_and_activation_without_changes(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $before = $invitation->fresh()->getRawOriginal();
        config(['app.key' => 'short']);
        $service = app(StaffAccounts::class);
        $this->assertFalse($service->ready());
        $this->assertSame('unavailable', $service->send($admin, $invitation));
        $this->rejected($admin, $invitation, '123456');
        $this->assertSame($before, $invitation->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_challenge_hash_is_bound_to_the_original_staff_setup_and_email(): void
    {
        $admin = $this->admin();
        $first = $this->invite($admin);
        $second = $this->invite($admin, ['email' => 'second-staff@example.test']);
        $this->accepted();
        $service = app(StaffAccounts::class);
        $service->send($admin, $first);
        $first->refresh();
        // Copying a challenge to a different row cannot authorize a different staff email.
        $second->update(['code_hash' => $first->code_hash, 'nonce' => $first->nonce, 'expires_at' => $first->expires_at]);
        $this->rejected($admin, $second, $this->codes[0]);
        $this->assertNull($second->fresh()->consumed_at);
        $staff = $service->verify($admin, $first, $this->codes[0]);
        $this->assertSame($first->email, $staff->email);
        $this->assertSame(1, User::where('role', 'staff')->count());
    }

    public function test_resend_waits_one_minute_replaces_old_code_and_preserves_hourly_limit(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->accepted();
        $service = app(StaffAccounts::class);
        $service->send($admin, $invitation);
        $original = $invitation->fresh()->getRawOriginal();
        $this->assertSame('cooldown', $service->send($admin, $invitation));
        $this->assertSame($original, $invitation->fresh()->getRawOriginal());
        $this->travel(59)->seconds();
        $this->assertSame('cooldown', $service->send($admin, $invitation));
        $this->travel(1)->second();
        $this->assertSame('sent', $service->send($admin, $invitation));
        $this->assertNotSame($original['nonce'], $invitation->fresh()->nonce);
        $this->assertSame(2, $invitation->fresh()->send_count);
        // The old nonce is invalid even if the random number happens to repeat.
        $this->assertNotSame($original['code_hash'], $invitation->fresh()->code_hash);
        if ($this->codes[0] !== $this->codes[1]) {
            $this->rejected($admin, $invitation, $this->codes[0]);
        }
        $staff = $service->verify($admin, $invitation, $this->codes[1]);
        $this->assertSame('staff', $staff->role);
        Http::assertSentCount(2);
    }

    public function test_five_emails_per_hour_limit_persists_across_service_instances_and_resets_at_window_end(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->accepted();
        for ($send = 0; $send < 5; $send++) {
            $this->assertSame('sent', (new StaffAccounts)->send($admin, $invitation));
            $this->travel(1)->minute();
        }
        $this->assertSame('limited', (new StaffAccounts)->send($admin, $invitation));
        $this->assertTrue(app(StaffAccounts::class)->state($invitation)['resend_at']->equalTo(Carbon::parse('2026-10-16 17:00:00', 'Asia/Manila')));
        $this->travel(55)->minutes();
        $this->assertSame('sent', (new StaffAccounts)->send($admin, $invitation));
        $this->assertSame(1, $invitation->fresh()->send_count);
        Http::assertSentCount(6);
    }

    public function test_provider_timeout_keeps_code_usable_without_blind_resending(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $delivered = null;
        $attempted = 0;
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function ($request) use (&$delivered, &$attempted) {
            $attempted++;
            preg_match('/staff setup code is ([0-9]{6})\./', $request['textContent'], $matches);
            $delivered = $matches[1];
            throw new ConnectionException('Provider response timed out');
        }]);
        $service = app(StaffAccounts::class);
        $this->assertSame('unknown', $service->send($admin, $invitation));
        $this->assertSame('unknown', $invitation->fresh()->status);
        $this->assertSame('cooldown', $service->send($admin, $invitation));
        $this->assertNotNull($invitation->fresh()->code_hash);
        $staff = $service->verify($admin, $invitation, $delivered);
        $this->assertSame('staff', $staff->role);
        $this->assertSame(1, $attempted);
    }

    public function test_definitive_provider_rejection_clears_code_and_never_activates_staff(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['code' => 'unauthorized', 'message' => 'Mock credentials'], 401)]);
        $service = app(StaffAccounts::class);
        $this->assertSame('failed', $service->send($admin, $invitation));
        $this->assertSame('failed', $service->state($invitation)['status']);
        $this->assertNull($invitation->fresh()->code_hash);
        $this->rejected($admin, $invitation, '123456');
        $this->assertSame(1, User::count());
        Http::assertSentCount(1);
    }

    public function test_unexpected_success_without_provider_message_id_is_not_claimed_as_sent(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response([], 201)]);
        $this->assertSame('unknown', app(StaffAccounts::class)->send($admin, $invitation));
        $this->assertNotNull($invitation->fresh()->code_hash);
        $this->assertSame(0, ActivityLog::where('action', 'staff.code_sent')->count());
    }

    public function test_newly_registered_customer_with_pending_email_cannot_be_overwritten_during_activation(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->accepted();
        $service = app(StaffAccounts::class);
        $service->send($admin, $invitation);
        $customer = User::factory()->create(['email' => $invitation->email, 'role' => 'customer']);
        $snapshot = $customer->fresh()->getRawOriginal();
        $this->rejected($admin, $invitation, $this->codes[0]);
        $this->assertSame('email_taken', $service->send($admin, $invitation));
        $this->assertSame('email_taken', $service->state($invitation)['status']);
        $this->assertSame($snapshot, $customer->fresh()->getRawOriginal());
        $this->assertSame(0, User::where('role', 'staff')->count());
        $this->assertNull($invitation->fresh()->consumed_at);
        Http::assertSentCount(1);
    }

    public function test_creation_rolls_back_when_audit_storage_fails(): void
    {
        $admin = $this->admin();
        $this->mock(ActivityRecorder::class)->shouldReceive('record')->once()->andReturnNull();
        try {
            $this->invite($admin);
            $this->fail('Staff setup must require an activity record.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The staff account activity could not be recorded.', $exception->getMessage());
        }
        $this->assertDatabaseCount('staff_invitations', 0);
        $this->assertSame(1, User::count());
    }

    public function test_activation_rolls_back_account_and_preserves_code_if_audit_fails(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $this->accepted();
        $service = app(StaffAccounts::class);
        $service->send($admin, $invitation);
        $before = $invitation->fresh()->getRawOriginal();
        $this->mock(ActivityRecorder::class)->shouldReceive('record')->once()->andReturnNull();
        try {
            $service->verify($admin, $invitation, $this->codes[0]);
            $this->fail('Staff activation must require an activity record.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The staff account activity could not be recorded.', $exception->getMessage());
        }
        $this->assertSame($before, $invitation->fresh()->getRawOriginal());
        $this->assertSame(1, User::count());
        $this->assertSame(0, ActivityLog::where('action', 'staff.activated')->count());
    }

    public function test_old_provider_response_cannot_replace_a_newer_code_claim(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin);
        $service = app(StaffAccounts::class);
        $once = false;
        $latestNonce = null;
        $this->accepted(function () use ($admin, $invitation, $service, &$once, &$latestNonce) {
            if (! $once) {
                $once = true;
                $this->travel(1)->minute();
                $this->assertSame('sent', $service->send($admin, $invitation));
                $latestNonce = $invitation->fresh()->nonce;
            }
        });
        $this->assertSame('sent', $service->send($admin, $invitation));
        $this->assertSame($latestNonce, $invitation->fresh()->nonce);
        $this->assertSame(2, $invitation->fresh()->send_count);
        $this->assertSame(1, ActivityLog::where('action', 'staff.code_sent')->count());
        $staff = $service->verify($admin, $invitation, $this->codes[1]);
        $this->assertSame('staff', $staff->role);
        Http::assertSentCount(2);
    }

    public function test_email_and_html_are_escaped_and_never_contain_the_initial_password(): void
    {
        $admin = $this->admin();
        $invitation = $this->invite($admin, ['name' => '<script>bad()</script>']);
        $this->accepted();
        app(StaffAccounts::class)->send($admin, $invitation);
        Http::assertSent(fn ($request) => str_contains($request['htmlContent'], '&lt;script&gt;bad()&lt;/script&gt;')
            && ! str_contains($request['htmlContent'], '<script>bad()</script>') && ! str_contains($request->body(), 'InitialStaff!2026'));
        $this->actingAs($admin)->get('/admin/staff/'.$invitation->id)->assertOk()->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)
            ->assertDontSee('<script>bad()</script>', false);
    }
}
