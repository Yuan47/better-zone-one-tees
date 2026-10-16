<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\EmailVerificationCodes;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationCodeTest extends TestCase
{
    use RefreshDatabase;

    private array $deliveredCodes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-16 16:00:00', 'Asia/Manila'));
        config(['auth.email_verification.required' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('v', 32)),
            'services.brevo.api_key' => 'mock-brevo-key', 'services.brevo.from_email' => 'accounts@zoneone.example',
            'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function user(): User
    {
        return User::factory()->unverified()->create(['role' => 'customer']);
    }

    private function accepted(?\Closure $duringSend = null): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function ($request) use ($duringSend) {
            preg_match('/verification code is ([0-9]{6})\./', $request['textContent'], $matches);
            $this->deliveredCodes[] = $matches[1];
            if ($duringSend) {
                $duringSend($request);
            }

            return Http::response(['messageId' => '<verification-'.count($this->deliveredCodes).'@brevo.example>'], 201);
        }]);
    }

    private function rejectCode(User $user, string $code): array
    {
        try {
            app(EmailVerificationCodes::class)->verify($user, $code);
            $this->fail('Verification should have rejected this code.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());

            return $exception->errors();
        }
    }

    public function test_sending_uses_brevo_and_only_stores_a_keyed_challenge_with_safe_state(): void
    {
        $user = $this->user();
        $this->accepted(function ($request) use ($user) {
            // The persisted claim exists before the first provider call.
            $this->assertSame('claimed', EmailVerificationCode::sole()->status);
            $this->assertSame($user->email, $request['to'][0]['email']);
        });
        $service = app(EmailVerificationCodes::class);
        $this->assertTrue($service->required());
        $this->assertTrue($service->configured());
        $this->assertTrue($service->ready());
        $this->assertSame('sent', $service->send($user));
        $challenge = EmailVerificationCode::sole();
        $code = $this->deliveredCodes[0];
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
        $this->assertSame(64, strlen($challenge->code_hash));
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertNotSame(hash('sha256', $code), $challenge->code_hash);
        $this->assertSame($user->id, $challenge->user_id);
        $this->assertSame($user->email, $challenge->email);
        $this->assertTrue($challenge->expires_at->equalTo(now()->addMinutes(10)));
        $this->assertTrue($challenge->resend_at->equalTo(now()->addMinute()));
        $this->assertSame(1, $challenge->send_count);
        $this->assertSame(0, $challenge->attempts);
        $this->assertSame('sent', $challenge->status);
        $this->assertSame(201, $challenge->provider_status);
        $this->assertNull($user->fresh()->email_verified_at);
        $state = $service->state($user);
        $this->assertSame(['status', 'expires_at', 'resend_at', 'attempts_remaining'], array_keys($state));
        $this->assertSame('pending', $state['status']);
        $this->assertSame(5, $state['attempts_remaining']);
        $this->assertArrayNotHasKey('code_hash', $challenge->toArray());
        $this->assertArrayNotHasKey('nonce', $challenge->toArray());
        $this->assertArrayNotHasKey('email', $challenge->toArray());
        $this->assertFalse(str_contains(json_encode(DB::table('email_verification_codes')->first()), '"'.$code.'"'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->header('api-key') === ['mock-brevo-key']
            && $request['sender']['email'] === 'accounts@zoneone.example' && $request['headers']['IdempotencyKey'] === $challenge->nonce
            && str_contains($request['htmlContent'], $code) && $request['tags'] === ['account-email-verification']);
    }

    public function test_correct_code_verifies_only_its_owner_once_and_logs_no_code_material(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->accepted();
        $service = app(EmailVerificationCodes::class);
        $service->send($user);
        $code = $this->deliveredCodes[0];
        $this->rejectCode($other, $code);
        $this->assertNull($other->fresh()->email_verified_at);
        $this->assertTrue($service->verify($user, $code));
        $verifiedAt = $user->fresh()->email_verified_at;
        $this->assertTrue($verifiedAt->equalTo(now()));
        $this->assertSame('verified', $service->state($user)['status']);
        $this->assertNull(EmailVerificationCode::sole()->code_hash);
        $this->assertNotNull(EmailVerificationCode::sole()->consumed_at);
        $this->travel(1)->minute();
        $this->rejectCode($user, $code);
        $this->assertTrue($user->fresh()->email_verified_at->equalTo($verifiedAt));
        $this->assertSame('already_verified', $service->send($user));
        $log = ActivityLog::where('action', 'auth.email_verified')->sole();
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->id, $log->target_id);
        $this->assertSame([], $log->metadata);
        $this->assertFalse(str_contains(json_encode($log->toArray()), $code));
        Http::assertSentCount(1);
    }

    public function test_rolling_back_a_failed_activity_write_also_preserves_unverified_state_and_unused_code(): void
    {
        $user = $this->user();
        $this->accepted();
        $service = app(EmailVerificationCodes::class);
        $service->send($user);
        $this->mock(ActivityRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit storage failure'));
        try {
            $service->verify($user, $this->deliveredCodes[0]);
            $this->fail('The storage failure should abort verification.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit storage failure', $exception->getMessage());
        }
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNotNull(EmailVerificationCode::sole()->code_hash);
        $this->assertNull(EmailVerificationCode::sole()->consumed_at);
    }

    public function test_expiry_is_strict_at_ten_minutes_and_invalidates_the_challenge(): void
    {
        $user = $this->user();
        $this->accepted();
        $service = app(EmailVerificationCodes::class);
        $service->send($user);
        $this->travel(10)->minutes();
        $this->assertSame('expired', $service->state($user)['status']);
        $this->rejectCode($user, $this->deliveredCodes[0]);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull(EmailVerificationCode::sole()->code_hash);
    }

    public function test_five_wrong_guesses_persist_and_invalidate_even_the_correct_code(): void
    {
        $user = $this->user();
        $this->accepted();
        $service = app(EmailVerificationCodes::class);
        $service->send($user);
        $wrong = $this->deliveredCodes[0] === '000000' ? '000001' : '000000';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->rejectCode($user, $wrong);
            $this->assertSame($attempt, EmailVerificationCode::sole()->attempts);
            $this->assertSame(5 - $attempt, $service->state($user)['attempts_remaining']);
        }
        $this->assertSame('locked', $service->state($user)['status']);
        $this->assertNull(EmailVerificationCode::sole()->code_hash);
        $this->rejectCode($user, $this->deliveredCodes[0]);
        $this->assertSame(5, EmailVerificationCode::sole()->attempts);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public static function malformedCodes(): array
    {
        return [[''], ['12345'], ['1234567'], [' 123456'], ['123456 '], ['1e0001'], ['１２３４５６'], ["123456\n"]];
    }

    #[DataProvider('malformedCodes')]
    public function test_malformed_codes_cannot_verify_and_still_consume_a_guess(string $code): void
    {
        $user = $this->user();
        $this->accepted();
        $service = app(EmailVerificationCodes::class);
        $service->send($user);
        $this->rejectCode($user, $code);
        $this->assertSame(1, EmailVerificationCode::sole()->attempts);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_change_invalidates_a_code_and_does_not_reset_send_limit(): void
    {
        $user = $this->user();
        $this->accepted();
        $service = app(EmailVerificationCodes::class);
        $service->send($user);
        $user->update(['email' => 'changed@example.test']);
        $this->assertSame('expired', $service->state($user)['status']);
        $this->rejectCode($user, $this->deliveredCodes[0]);
        $this->assertSame('cooldown', $service->send($user));
        $this->travel(1)->minute();
        $this->assertSame('sent', $service->send($user));
        $this->assertSame(2, EmailVerificationCode::sole()->send_count);
        $this->assertSame('changed@example.test', EmailVerificationCode::sole()->email);
        $this->assertTrue($service->verify($user, $this->deliveredCodes[1]));
    }

    public function test_resend_cooldown_is_persistent_and_old_code_no_longer_works(): void
    {
        $user = $this->user();
        $this->accepted();
        $service = app(EmailVerificationCodes::class);
        $this->assertSame('sent', $service->send($user));
        $before = EmailVerificationCode::sole()->getRawOriginal();
        $this->assertSame('cooldown', (new EmailVerificationCodes)->send($user));
        $this->assertSame($before, EmailVerificationCode::sole()->getRawOriginal());
        $this->travel(59)->seconds();
        $this->assertSame('cooldown', $service->send($user));
        $this->travel(1)->second();
        $this->assertSame('sent', $service->send($user));
        $after = EmailVerificationCode::sole();
        $this->assertNotSame($before['nonce'], $after->nonce);
        $this->assertNotSame($before['code_hash'], $after->code_hash);
        $this->assertDatabaseCount('email_verification_codes', 1);
        // Force a distinct old value if the cryptographic generator happens to repeat six digits.
        $oldCode = $this->deliveredCodes[0];
        if ($oldCode !== $this->deliveredCodes[1]) {
            $this->rejectCode($user, $oldCode);
        }
        $this->assertTrue($service->verify($user, $this->deliveredCodes[1]));
        Http::assertSentCount(2);
    }

    public function test_five_sends_per_hour_are_enforced_in_database_across_service_instances(): void
    {
        $user = $this->user();
        $this->accepted();
        for ($send = 1; $send <= 5; $send++) {
            $this->assertSame('sent', (new EmailVerificationCodes)->send($user));
            $this->travel(1)->minute();
        }
        $service = new EmailVerificationCodes;
        $this->assertSame('limited', $service->send($user));
        $this->assertSame(5, EmailVerificationCode::sole()->send_count);
        $this->assertSame('2026-10-16T17:00:00+08:00', $service->state($user)['resend_at']);
        $this->travel(54)->minutes();
        $this->travel(59)->seconds();
        $this->assertSame('limited', $service->send($user));
        $this->travel(1)->second();
        $this->assertSame('sent', $service->send($user));
        $this->assertSame(1, EmailVerificationCode::sole()->send_count);
        Http::assertSentCount(6);
    }

    public static function unavailableConfigurations(): array
    {
        return [['auth.email_verification.required', false], ['auth.email_verification.required', 'true'],
            ['services.brevo.api_key', null], ['services.brevo.api_key', "key\nvalue"],
            ['services.brevo.from_email', 'not-an-email'], ['services.brevo.from_email', "a@example.test\nb@example.test"],
            ['services.brevo.from_name', ''], ['services.brevo.from_name', "Store\rName"], ['services.brevo.from_name', str_repeat('x', 101)],
            ['app.key', null], ['app.key', 'weak'], ['app.key', 'base64:invalid!!']];
    }

    #[DataProvider('unavailableConfigurations')]
    public function test_disabled_or_incomplete_configuration_never_sends_or_creates_a_code(string $key, mixed $value): void
    {
        $user = $this->user();
        config([$key => $value]);
        $service = app(EmailVerificationCodes::class);
        $this->assertSame('unavailable', $service->send($user));
        $this->assertSame('unavailable', $service->state($user)['status']);
        $this->assertDatabaseCount('email_verification_codes', 0);
        $this->assertNull($user->fresh()->email_verified_at);
        Http::assertNothingSent();
    }

    public static function providerFailures(): array
    {
        return [[400, 'failed'], [401, 'failed'], [403, 'failed'], [422, 'failed'], [429, 'failed'],
            [301, 'unknown'], [500, 'unknown'], [503, 'unknown'], [201, 'unknown'], [200, 'unknown']];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_is_not_retried_and_returns_safe_status(int $httpStatus, string $expected): void
    {
        $user = $this->user();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['message' => 'Private provider message'], $httpStatus)]);
        $service = app(EmailVerificationCodes::class);
        $this->assertSame($expected, $service->send($user));
        $this->assertSame($expected, EmailVerificationCode::sole()->status);
        $this->assertSame($httpStatus, EmailVerificationCode::sole()->provider_status);
        $this->assertSame(1, EmailVerificationCode::sole()->send_count);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame('cooldown', $service->send($user));
        if ($expected === 'failed') {
            $this->assertNull(EmailVerificationCode::sole()->code_hash);
        }
        Http::assertSentCount(1);
    }

    public function test_uncertain_timeout_code_can_still_verify_if_the_email_arrives(): void
    {
        $user = $this->user();
        $code = null;
        $calls = 0;
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function ($request) use (&$code, &$calls) {
            $calls++;
            preg_match('/verification code is ([0-9]{6})\./', $request['textContent'], $matches);
            $code = $matches[1];
            throw new ConnectionException('Simulated timeout containing private provider details');
        }]);
        $service = app(EmailVerificationCodes::class);
        $this->assertSame('unknown', $service->send($user));
        $this->assertSame('unknown', EmailVerificationCode::sole()->status);
        $this->assertSame('pending', $service->state($user)['status']);
        $this->assertTrue($service->verify($user, $code));
        $this->assertSame(1, $calls);
    }

    public function test_state_never_shortens_a_last_minute_send_cooldown_when_hourly_limit_ends(): void
    {
        $user = $this->user();
        $this->accepted();
        $service = app(EmailVerificationCodes::class);
        for ($send = 1; $send <= 4; $send++) {
            $this->assertSame('sent', $service->send($user));
            $this->travel(1)->minute();
        }
        $this->travelTo(Carbon::parse('2026-10-16 16:59:50', 'Asia/Manila'));
        $this->assertSame('sent', $service->send($user));
        $this->assertSame('2026-10-16T17:00:50+08:00', $service->state($user)['resend_at']);
        $this->travel(10)->seconds();
        $this->assertSame('cooldown', $service->send($user));
        $this->assertSame('2026-10-16T17:00:50+08:00', $service->state($user)['resend_at']);
        $this->travel(50)->seconds();
        $this->assertSame('sent', $service->send($user));
        $this->assertSame(1, EmailVerificationCode::sole()->send_count);
        Http::assertSentCount(6);
    }

    public function test_a_leading_zero_code_requires_all_six_original_digits(): void
    {
        $user = $this->user();
        $this->accepted();
        $service = app(EmailVerificationCodes::class);
        $service->send($user);
        $challenge = EmailVerificationCode::sole();
        // An explicit issued fixture verifies the string boundary independently of random generator output.
        $digest = hash_hmac('sha256', implode("\0", [(string) $user->id, $user->email, $challenge->nonce, '000123']), str_repeat('v', 32));
        $challenge->update(['code_hash' => $digest]);
        $this->rejectCode($user, '123');
        $this->assertTrue($service->verify($user, '000123'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verification_completed_while_provider_response_is_pending_is_not_overwritten(): void
    {
        $user = $this->user();
        $this->accepted(function () use ($user) {
            $this->assertTrue(app(EmailVerificationCodes::class)->verify($user, $this->deliveredCodes[0]));
        });
        $service = app(EmailVerificationCodes::class);
        $this->assertSame('sent', $service->send($user));
        $this->assertSame('verified', EmailVerificationCode::sole()->status);
        $this->assertNull(EmailVerificationCode::sole()->code_hash);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_delayed_provider_response_does_not_overwrite_a_newer_resend(): void
    {
        $user = $this->user();
        $requestNumber = 0;
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function ($request) use ($user, &$requestNumber) {
            $number = ++$requestNumber;
            if ($number === 1) {
                $this->travel(1)->minute();
                $this->assertSame('sent', app(EmailVerificationCodes::class)->send($user));
            }

            return Http::response(['messageId' => '<response-'.$number.'@brevo.example>'], 201);
        }]);
        $this->assertSame('sent', app(EmailVerificationCodes::class)->send($user));
        $challenge = EmailVerificationCode::sole();
        $this->assertSame(2, $challenge->send_count);
        $this->assertSame('<response-2@brevo.example>', $challenge->provider_message_id);
        $this->assertSame('sent', $challenge->status);
        Http::assertSentCount(2);
    }

    public function test_codes_are_bound_to_application_key_and_current_user_even_when_digits_match(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->accepted();
        $service = app(EmailVerificationCodes::class);
        $service->send($user);
        $code = $this->deliveredCodes[0];
        $original = EmailVerificationCode::sole();
        EmailVerificationCode::create(array_merge($original->getAttributes(), ['id' => null, 'user_id' => $other->id, 'email' => $other->email]));
        $this->rejectCode($other, $code);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
        $this->rejectCode($user, $code);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
    }
}
