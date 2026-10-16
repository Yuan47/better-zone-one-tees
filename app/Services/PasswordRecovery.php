<?php

namespace App\Services;

use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordRecovery
{
    public const NOTICE = 'If an account uses that email, a password reset link will be sent. Check your inbox and spam folder. Please wait at least one minute before requesting another link.';

    public const INVALID_LINK = 'This password reset link is invalid or has expired. Request a new link and try again.';

    public function enabled(): bool
    {
        return config('auth.password_recovery.enabled') === true;
    }

    public function ready(): bool
    {
        return $this->enabled() && app(BrevoTransactions::class)->configured() && $this->baseUrl() !== null;
    }

    /** Account existence, throttling, and provider outcomes intentionally have the same public response. */
    public function request(string $email): void
    {
        if (! $this->ready()) {
            return;
        }

        $claim = DB::transaction(function () use ($email) {
            // Lock the account even when no token exists yet. Concurrent sends and resets use the same lock.
            $account = $this->account($email);
            if (! $account) {
                return null;
            }

            $claim = null;
            Password::broker('users')->sendResetLink(['email' => $account->email], function (User $user, #[\SensitiveParameter] string $token) use (&$claim) {
                $claim = ['email' => $user->email, 'name' => $user->name, 'token' => $token];
            });

            return $claim;
        }, 3);

        if ($claim === null) {
            return;
        }

        // The hashed token and cooldown are committed first. No HTTP occurs in a retryable database transaction.
        // If sending is uncertain, the token remains usable and no automatic retry can create duplicate emails.
        $url = $this->baseUrl().'/reset-password/'.rawurlencode($claim['token']).'?email='.rawurlencode($claim['email']);
        $payload = [
            'to' => [['email' => $claim['email'], 'name' => $claim['name']]],
            'subject' => 'Reset your Zone One Tees password',
            'htmlContent' => view('mail.password-reset', ['name' => $claim['name'], 'url' => $url])->render(),
            'textContent' => 'Hello '.$claim['name'].",\n\nReset your Zone One Tees password using this link:\n".$url
                ."\n\nThis link expires in 60 minutes and can only be used once. If you did not request a reset, ignore this email. Your password will stay the same.",
            'headers' => ['IdempotencyKey' => (string) Str::uuid()],
            'tags' => ['account-password-recovery'],
        ];
        app(BrevoTransactions::class)->send($payload);
    }

    /** The broker still validates and consumes its own hashed, expiring token inside the account lock. */
    public function reset(#[\SensitiveParameter] array $credentials): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return DB::transaction(function () use ($credentials) {
            $account = $this->account($credentials['email']);
            if (! $account) {
                return false;
            }

            $status = Password::broker('users')->reset([
                'email' => $account->email, 'token' => $credentials['token'], 'password' => $credentials['password'],
            ], function (User $user, #[\SensitiveParameter] string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                EmailVerificationCode::where('user_id', $user->id)->update([
                    'code_hash' => null, 'status' => 'invalidated', 'consumed_at' => now(), 'updated_at' => now(),
                ]);
                if (app(ActivityRecorder::class)->record($user, 'auth.password_reset', $user) === null) {
                    throw new \RuntimeException('The password change could not be recorded.');
                }
                DB::afterCommit(fn () => event(new PasswordReset($user)));
            });

            return $status === Password::PASSWORD_RESET;
        }, 3);
    }

    private function account(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->lockForUpdate()->first();
    }

    private function baseUrl(): ?string
    {
        $base = config('app.url');
        if (! is_string($base) || strlen($base) > 2000 || preg_match('/[\r\n]/', $base)) {
            return null;
        }
        $parts = parse_url($base);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return rtrim($base, '/');
    }
}
