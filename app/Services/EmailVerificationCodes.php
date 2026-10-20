<?php

namespace App\Services;

use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailVerificationCodes
{
    public function required(): bool
    {
        return config('auth.email_verification.required') === true;
    }

    public function configured(): bool
    {
        $key = config('services.brevo.api_key');
        $name = config('services.brevo.from_name');

        return is_string($key) && trim($key) !== '' && ! preg_match('/[\r\n]/', $key)
            && $this->validEmail(config('services.brevo.from_email'))
            && is_string($name) && trim($name) !== '' && mb_strlen(trim($name)) <= BrevoTransactions::DISPLAY_NAME_LIMIT && ! preg_match('/[\r\n]/', $name);
    }

    public function ready(): bool
    {
        return $this->required() && $this->configured();
    }

    public function send(User $user): string
    {
        if (! $this->ready() || $this->hashKey() === null) {
            return 'unavailable';
        }

        // Lock the existing account before creating its one challenge. This also serializes the first send.
        $claim = DB::transaction(function () use ($user) {
            $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($account->email_verified_at !== null) {
                return 'already_verified';
            }
            if (! $this->validEmail($account->email)) {
                return 'unavailable';
            }
            $challenge = EmailVerificationCode::where('user_id', $account->id)->lockForUpdate()->first();
            $clock = now();
            $sameWindow = $challenge && $challenge->send_window_started_at->copy()->addHour()->isAfter($clock);
            if ($sameWindow && $challenge->send_count >= 5) {
                return 'limited';
            }
            if ($challenge && $challenge->resend_at->isAfter($clock)) {
                return 'cooldown';
            }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $nonce = (string) Str::uuid();
            $attributes = ['email' => $account->email, 'nonce' => $nonce,
                'code_hash' => $this->digest($account->id, $account->email, $nonce, $code),
                'status' => 'claimed', 'expires_at' => $clock->copy()->addMinutes(10), 'resend_at' => $clock->copy()->addMinute(),
                'attempts' => 0, 'send_window_started_at' => $sameWindow ? $challenge->send_window_started_at : $clock,
                'send_count' => $sameWindow ? $challenge->send_count + 1 : 1,
                'provider_status' => null, 'provider_message_id' => null, 'consumed_at' => null];
            if ($challenge) {
                $challenge->update($attributes);
            } else {
                $challenge = EmailVerificationCode::create($attributes + ['user_id' => $account->id]);
            }

            return ['id' => $challenge->id, 'nonce' => $nonce, 'email' => $account->email, 'name' => $account->name, 'code' => $code];
        }, 3);
        if (is_string($claim)) {
            return $claim;
        }

        // The claim is committed before HTTP. A timeout may still deliver mail, so never automatically retry it.
        try {
            $html = view('mail.email-verification-code', ['code' => $claim['code'], 'name' => $claim['name']])->render();
        } catch (\Throwable $exception) {
            $this->finish($claim, 'failed');

            return 'failed';
        }
        try {
            $response = Http::withHeaders(['api-key' => trim(config('services.brevo.api_key'))])->acceptJson()->asJson()
                ->withoutRedirecting()->connectTimeout(3)->timeout(8)
                ->post('https://api.brevo.com/v3/smtp/email', [
                    'sender' => ['name' => trim(config('services.brevo.from_name')), 'email' => trim(config('services.brevo.from_email'))],
                    'to' => [['email' => $claim['email'], 'name' => BrevoTransactions::recipientName($claim['name'])]],
                    'subject' => 'Verify your Zone One Tees email address', 'htmlContent' => $html,
                    'textContent' => 'Hello '.$claim['name'].', your Zone One Tees email verification code is '.$claim['code']
                        .'. It expires in 10 minutes. Never share this code. If you did not create or verify an account, ignore this email.',
                    'headers' => ['IdempotencyKey' => $claim['nonce']], 'tags' => ['account-email-verification'],
                ]);
        } catch (\Throwable $exception) {
            $this->finish($claim, 'unknown');

            return 'unknown';
        }
        $message = $response->json('messageId');
        if ($response->status() === 201 && is_string($message) && trim($message) !== '' && strlen($message) <= 255) {
            $this->finish($claim, 'sent', 201, $message);

            return 'sent';
        }
        $status = $response->status() >= 400 && $response->status() < 500 ? 'failed' : 'unknown';
        $this->finish($claim, $status, $response->status());

        return $status;
    }

    public function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        if (! $this->required() || $this->hashKey() === null) {
            throw ValidationException::withMessages(['code' => 'Email verification is unavailable. Please contact the store.']);
        }
        // Return failures from the transaction so unsuccessful guesses remain recorded when validation is raised.
        $error = DB::transaction(function () use ($user, $code) {
            $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $challenge = EmailVerificationCode::where('user_id', $account->id)->lockForUpdate()->first();
            if ($account->email_verified_at !== null || ! $challenge || $challenge->consumed_at !== null) {
                return 'This code is no longer available. Request a new code if your email is not yet verified.';
            }
            if ($challenge->email !== $account->email || ! $challenge->expires_at->isAfter(now())) {
                $challenge->update(['code_hash' => null, 'status' => 'expired']);

                return 'This code has expired. Request a new verification code.';
            }
            if ($challenge->attempts >= 5 || $challenge->code_hash === null) {
                return 'This code is no longer available. Request a new verification code.';
            }
            $matches = preg_match('/\A[0-9]{6}\z/D', $code) === 1
                && hash_equals($challenge->code_hash, $this->digest($account->id, $account->email, $challenge->nonce, $code));
            if (! $matches) {
                $attempts = $challenge->attempts + 1;
                $challenge->update(['attempts' => $attempts, 'code_hash' => $attempts >= 5 ? null : $challenge->code_hash,
                    'status' => $attempts >= 5 ? 'locked' : $challenge->status]);

                return $attempts >= 5 ? 'Too many incorrect attempts. Request a new verification code.' : 'The verification code is incorrect.';
            }
            $account->forceFill(['email_verified_at' => now()])->save();
            $challenge->update(['code_hash' => null, 'status' => 'verified', 'consumed_at' => now()]);
            app(ActivityRecorder::class)->record($account, 'auth.email_verified', $account);

            return null;
        }, 3);
        if ($error !== null) {
            throw ValidationException::withMessages(['code' => $error]);
        }

        return true;
    }

    public function state(User $user): array
    {
        $account = User::findOrFail($user->id);
        $challenge = EmailVerificationCode::where('user_id', $account->id)->first();
        $status = match (true) {
            $account->email_verified_at !== null => 'verified',
            ! $this->ready() || $this->hashKey() === null => 'unavailable',
            ! $challenge => 'none',
            $challenge->email !== $account->email || ! $challenge->expires_at->isAfter(now()) => 'expired',
            $challenge->attempts >= 5 => 'locked',
            $challenge->status === 'failed' || $challenge->code_hash === null => 'failed',
            default => 'pending',
        };
        $resendAt = $challenge?->resend_at;
        if ($challenge && $challenge->send_count >= 5 && $challenge->send_window_started_at->copy()->addHour()->isAfter(now())) {
            $windowEnd = $challenge->send_window_started_at->copy()->addHour();
            $resendAt = $resendAt->isAfter($windowEnd) ? $resendAt : $windowEnd;
        }

        return ['status' => $status, 'expires_at' => $challenge?->expires_at->toIso8601String(),
            'resend_at' => $resendAt?->toIso8601String(),
            'attempts_remaining' => $status === 'pending' ? max(0, 5 - $challenge->attempts) : 0];
    }

    private function finish(#[\SensitiveParameter] array $claim, string $status, ?int $providerStatus = null, ?string $messageId = null): void
    {
        $attributes = ['status' => $status, 'provider_status' => $providerStatus, 'provider_message_id' => $messageId];
        if ($status === 'failed') {
            $attributes['code_hash'] = null;
        }
        // A delayed response must not overwrite a newer resend, successful verification, or exhausted challenge.
        EmailVerificationCode::whereKey($claim['id'])->where('nonce', $claim['nonce'])->where('status', 'claimed')
            ->whereNull('consumed_at')->update($attributes);
    }

    private function digest(int $userId, string $email, string $nonce, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', implode("\0", [(string) $userId, $email, $nonce, $code]), $this->hashKey());
    }

    private function hashKey(): ?string
    {
        $key = config('app.key');
        if (! is_string($key)) {
            return null;
        }
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        return is_string($key) && strlen($key) >= 32 ? $key : null;
    }

    private function validEmail(mixed $email): bool
    {
        return is_string($email) && strlen($email) <= 254 && ! preg_match('/[\r\n]/', $email)
            && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }
}
