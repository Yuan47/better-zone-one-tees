<?php

namespace App\Services;

use App\Models\StaffInvitation;
use App\Models\User;
use App\Rules\AccountPasswordSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class StaffAccounts
{
    public function ready(): bool
    {
        return config('auth.staff_setup.enabled') === true && app(BrevoTransactions::class)->configured() && $this->hashKey() !== null;
    }

    public function create(User $admin, #[\SensitiveParameter] array $data): StaffInvitation
    {
        $this->authorize($admin);
        $data['email'] = is_string($data['email'] ?? null) ? Str::lower(trim($data['email'])) : ($data['email'] ?? null);
        $data['name'] = is_string($data['name'] ?? null) ? trim($data['name']) : ($data['name'] ?? null);
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(10), new AccountPasswordSize],
        ])->validate();
        $passwordHash = Hash::make($validated['password']);
        unset($validated['password'], $data['password'], $data['password_confirmation']);
        try {
            return DB::transaction(function () use ($admin, $validated, $passwordHash) {
                if ($this->emailExists($validated['email']) || StaffInvitation::where('email', $validated['email'])->exists()) {
                    throw ValidationException::withMessages(['email' => 'This email already has an account or a staff setup. Use the existing account or open its pending setup.']);
                }
                $invitation = StaffInvitation::create(['name' => $validated['name'], 'email' => $validated['email'],
                    'password_hash' => $passwordHash, 'created_by' => $admin->id]);
                $this->audit($admin, 'staff.invited', $invitation);

                return $invitation;
            }, 3);
        } catch (QueryException $exception) {
            if ($this->emailExists($validated['email']) || StaffInvitation::where('email', $validated['email'])->exists()) {
                throw ValidationException::withMessages(['email' => 'This email already has an account or a staff setup. Refresh the staff list.']);
            }
            throw $exception;
        }
    }

    public function send(User $admin, StaffInvitation $invitation): string
    {
        $this->authorize($admin);
        if (! $this->ready()) {
            return 'unavailable';
        }
        $claim = DB::transaction(function () use ($invitation) {
            $pending = StaffInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($pending->consumed_at !== null) {
                return 'activated';
            }
            if ($this->emailExists($pending->email)) {
                return 'email_taken';
            }
            $clock = now();
            $sameWindow = $pending->send_window_started_at && $pending->send_window_started_at->copy()->addHour()->isAfter($clock);
            if ($sameWindow && $pending->send_count >= 5) {
                return 'limited';
            }
            if ($pending->resend_at?->isAfter($clock)) {
                return 'cooldown';
            }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $nonce = (string) Str::uuid();
            $pending->update(['nonce' => $nonce, 'code_hash' => $this->digest($pending, $nonce, $code), 'status' => 'claimed',
                'expires_at' => $clock->copy()->addMinutes(10), 'resend_at' => $clock->copy()->addMinute(), 'attempts' => 0,
                'send_window_started_at' => $sameWindow ? $pending->send_window_started_at : $clock,
                'send_count' => $sameWindow ? $pending->send_count + 1 : 1, 'provider_status' => null, 'provider_message_id' => null]);

            return ['id' => $pending->id, 'nonce' => $nonce, 'email' => $pending->email, 'name' => $pending->name, 'code' => $code];
        }, 3);
        if (is_string($claim)) {
            return $claim;
        }
        // Commit the send claim before HTTP. The provider may accept an email even when its response times out.
        try {
            $html = view('mail.staff-setup-code', ['name' => $claim['name'], 'code' => $claim['code']])->render();
        } catch (\Throwable $exception) {
            $this->finish($admin, $claim, ['status' => 'failed', 'provider_status' => null, 'message_id' => null]);

            return 'failed';
        }
        $result = app(BrevoTransactions::class)->send([
            'to' => [['email' => $claim['email'], 'name' => $claim['name']]], 'subject' => 'Confirm your Zone One Tees staff account',
            'htmlContent' => $html,
            'textContent' => 'Hello '.$claim['name'].', your Zone One Tees staff setup code is '.$claim['code']
                .'. It expires in 10 minutes. Give this code only to the store owner who is setting up your staff account in person. '
                .'If you did not expect this staff account, do not share the code and contact the store.',
            'headers' => ['IdempotencyKey' => $claim['nonce']], 'tags' => ['staff-account-setup'],
        ]);
        $this->finish($admin, $claim, $result);

        return $result['status'];
    }

    public function verify(User $admin, StaffInvitation $invitation, #[\SensitiveParameter] string $code): User
    {
        $this->authorize($admin);
        if (config('auth.staff_setup.enabled') !== true || $this->hashKey() === null) {
            throw ValidationException::withMessages(['code' => 'Staff verification is waiting for the store’s email setup.']);
        }
        try {
            $result = DB::transaction(function () use ($admin, $invitation, $code) {
                $pending = StaffInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
                if ($pending->consumed_at !== null) {
                    return 'This staff setup has already been completed. The code cannot be used again.';
                }
                if ($this->emailExists($pending->email)) {
                    return 'This email already has an account. It cannot be changed into a staff account through this setup.';
                }
                if (! $pending->expires_at || ! $pending->expires_at->isAfter(now())) {
                    if ($pending->expires_at) {
                        $pending->update(['code_hash' => null, 'status' => 'expired']);
                    }

                    return 'Request a new code. The previous code is missing or expired.';
                }
                if ($pending->attempts >= 5 || $pending->code_hash === null || $pending->password_hash === null) {
                    return 'This code is no longer available. Request a new code.';
                }
                $matches = preg_match('/\A[0-9]{6}\z/D', $code) === 1
                    && hash_equals($pending->code_hash, $this->digest($pending, $pending->nonce, $code));
                if (! $matches) {
                    $attempts = $pending->attempts + 1;
                    $pending->update(['attempts' => $attempts, 'code_hash' => $attempts >= 5 ? null : $pending->code_hash,
                        'status' => $attempts >= 5 ? 'locked' : $pending->status]);

                    return $attempts >= 5 ? 'Too many incorrect attempts. Request a new code.' : 'The verification code is incorrect.';
                }
                // Role and verification are server-owned. Pending invitations never receive login or reset access.
                $staff = new User;
                $staff->forceFill(['name' => $pending->name, 'email' => $pending->email, 'password' => $pending->password_hash,
                    'role' => 'staff', 'email_verified_at' => now()])->save();
                $pending->update(['status' => 'activated', 'code_hash' => null, 'password_hash' => null,
                    'consumed_at' => now(), 'activated_user_id' => $staff->id]);
                $this->audit($admin, 'staff.activated', $staff);

                return $staff;
            }, 3);
        } catch (QueryException $exception) {
            if ($this->emailExists($invitation->email)) {
                throw ValidationException::withMessages(['code' => 'This email already has an account. Refresh the staff list before continuing.']);
            }
            throw $exception;
        }
        if (is_string($result)) {
            throw ValidationException::withMessages(['code' => $result]);
        }

        return $result;
    }

    public function state(StaffInvitation $invitation): array
    {
        $pending = $invitation->fresh();
        $status = match (true) {
            $pending->consumed_at !== null => 'activated',
            $this->emailExists($pending->email) => 'email_taken',
            ! $this->ready() => 'unavailable',
            ! $pending->expires_at => 'draft',
            ! $pending->expires_at->isAfter(now()) => 'expired',
            $pending->attempts >= 5 => 'locked',
            $pending->code_hash === null => 'failed',
            default => 'pending',
        };
        $resendAt = $pending->resend_at;
        if ($pending->send_count >= 5 && $pending->send_window_started_at?->copy()->addHour()->isAfter(now())) {
            $windowEnd = $pending->send_window_started_at->copy()->addHour();
            $resendAt = $resendAt?->isAfter($windowEnd) ? $resendAt : $windowEnd;
        }

        return ['status' => $status, 'expires_at' => $pending->expires_at, 'resend_at' => $resendAt,
            'attempts_remaining' => $status === 'pending' ? max(0, 5 - $pending->attempts) : 0];
    }

    private function authorize(User $admin): void
    {
        abort_unless($admin->exists && User::whereKey($admin->id)->where('role', 'admin')->exists(), 403);
    }

    private function emailExists(string $email): bool
    {
        return User::whereRaw('LOWER(email) = ?', [Str::lower($email)])->exists();
    }

    private function audit(User $admin, string $action, Model $target): void
    {
        if (! app(ActivityRecorder::class)->record($admin, $action, $target)) {
            throw new \RuntimeException('The staff account activity could not be recorded.');
        }
    }

    private function finish(User $admin, #[\SensitiveParameter] array $claim, array $result): void
    {
        $attributes = ['status' => $result['status'], 'provider_status' => $result['provider_status'],
            'provider_message_id' => $result['message_id']];
        if (in_array($result['status'], ['failed', 'unavailable'], true)) {
            $attributes['code_hash'] = null;
        }
        DB::transaction(function () use ($admin, $claim, $result, $attributes) {
            $pending = StaffInvitation::whereKey($claim['id'])->lockForUpdate()->firstOrFail();
            if ($pending->nonce !== $claim['nonce'] || $pending->status !== 'claimed' || $pending->consumed_at !== null) {
                return;
            }
            $pending->update($attributes);
            if ($result['status'] === 'sent') {
                $this->audit($admin, 'staff.code_sent', $pending);
            }
        }, 3);
    }

    private function digest(StaffInvitation $invitation, string $nonce, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', implode("\0", ['staff-setup', (string) $invitation->id, $invitation->email, $nonce, $code]), $this->hashKey());
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
}
