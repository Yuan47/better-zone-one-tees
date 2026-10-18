<?php

namespace App\Services;

use App\Models\GoogleIdentity;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GoogleSignIn
{
    private const CONTEXT = 'google_oauth';

    public function ready(): bool
    {
        $config = config('services.google');
        if (! is_array($config) || ($config['enabled'] ?? false) !== true) {
            return false;
        }
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            if (! is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                return false;
            }
        }
        $callback = parse_url($config['redirect']);
        $app = parse_url((string) config('app.url'));
        if (! is_array($callback) || ! is_array($app) || isset($callback['user']) || isset($callback['pass'])
            || isset($callback['query']) || isset($callback['fragment'])
            || ! in_array($callback['scheme'] ?? null, ['http', 'https'], true)
            || ($callback['host'] ?? null) !== ($app['host'] ?? null)
            || ($callback['scheme'] ?? null) !== ($app['scheme'] ?? null)
            || ($callback['port'] ?? null) !== ($app['port'] ?? null)
            || ($callback['path'] ?? '') !== rtrim($app['path'] ?? '', '/').'/auth/google/callback') {
            return false;
        }

        return $callback['scheme'] === 'https' || in_array($callback['host'], ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public function begin(Request $request, bool $link = false)
    {
        $provider = $this->provider($request);
        $response = $provider->redirect();
        $request->session()->put(self::CONTEXT, [
            'mode' => $link ? 'link' : 'login', 'user_id' => $link ? $request->user()->id : null,
            'password_hash' => $link ? hash('sha256', $request->user()->getAuthPassword()) : null,
            'session_id' => $request->session()->getId(), 'expires_at' => now()->addMinutes(10)->timestamp,
            'state_hash' => hash('sha256', $request->session()->get('state')),
        ]);

        return $response;
    }

    public function consume(Request $request): array
    {
        $context = $request->session()->pull(self::CONTEXT);
        $state = $request->query('state');
        $valid = is_array($context) && is_string($state) && strlen($state) === 40
            && is_int($context['expires_at'] ?? null) && $context['expires_at'] > now()->timestamp
            && ($context['session_id'] ?? null) === $request->session()->getId()
            && is_string($context['state_hash'] ?? null) && hash_equals($context['state_hash'], hash('sha256', $state))
            && in_array($context['mode'] ?? null, ['login', 'link'], true);
        if ($valid) {
            $valid = $context['mode'] === 'login' ? $request->user() === null
                : $request->user()?->role === 'customer' && $request->user()->id === ($context['user_id'] ?? null)
                    && hash_equals((string) ($context['password_hash'] ?? ''), hash('sha256', $request->user()->getAuthPassword()));
        }
        // Atomic shared-cache claim also rejects racing callbacks with an older copy of the session.
        try {
            $claimed = $valid && Cache::add('google-oauth-used:'.hash('sha256', $state), true, now()->addMinutes(10));
        } catch (\Throwable) {
            $claimed = false;
        }
        if (! $claimed) {
            $this->clear($request);
            throw new GoogleSignInException('This Google sign-in has expired or was already used. Start again from this website.');
        }

        return $context;
    }

    public function clear(Request $request): void
    {
        $request->session()->forget([self::CONTEXT, 'state', 'code_verifier']);
    }

    public function identity(Request $request): array
    {
        try {
            $identity = $this->provider($request)->user()->getRaw();
        } catch (\Throwable) {
            // Provider exceptions can contain credentials or codes. Never expose or log their payloads.
            throw new GoogleSignInException('Google sign-in is temporarily unavailable or could not be verified. Please try again, or use your email and password.');
        }
        if (! is_array($identity) || ($identity['email_verified'] ?? false) !== true
            || ! is_string($identity['sub'] ?? null) || preg_match('/\A[A-Za-z0-9_-]{1,255}\z/D', $identity['sub']) !== 1
            || ! is_string($identity['email'] ?? null)) {
            throw new GoogleSignInException('Google did not provide a verified email address. Use your email and password instead.');
        }
        $email = Str::lower(trim($identity['email']));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/']])->fails()) {
            throw new GoogleSignInException('Google did not provide a valid verified email address.');
        }
        $name = is_string($identity['name'] ?? null) ? trim($identity['name']) : '';

        return ['subject' => $identity['sub'], 'email' => $email, 'name' => $name === '' ? 'Zone One Customer' : mb_substr($name, 0, 100)];
    }

    public function account(array $identity, array $context, Request $request): array
    {
        try {
            return DB::transaction(function () use ($identity, $context) {
                if ($context['mode'] === 'link') {
                    $user = User::whereKey($context['user_id'])->lockForUpdate()->first();
                    if (! $user || $user->role !== 'customer'
                        || ! hash_equals($context['password_hash'], hash('sha256', $user->getAuthPassword()))) {
                        throw new GoogleSignInException('Your account changed during Google linking. Sign in again and retry.');
                    }
                    $this->matchingEmail($user, $identity['email']);
                    $existing = GoogleIdentity::where('subject', $identity['subject'])->lockForUpdate()->first();
                    if (($existing && $existing->user_id !== $user->id)
                        || GoogleIdentity::where('user_id', $user->id)->where('subject', '!=', $identity['subject'])->exists()) {
                        throw new GoogleSignInException('That Google account is already linked, or this account already has a different Google sign-in.');
                    }
                    if (! $existing) {
                        GoogleIdentity::create(['subject' => $identity['subject'], 'user_id' => $user->id]);
                        if (! app(ActivityRecorder::class)->record($user, 'auth.google_linked', $user)) {
                            throw new GoogleSignInException('Google linking could not be recorded. Please try again.');
                        }
                    }
                    if ($user->email_verified_at === null) {
                        $user->forceFill(['email_verified_at' => now()])->save();
                    }

                    return [$user, false];
                }
                $existing = GoogleIdentity::where('subject', $identity['subject'])->lockForUpdate()->first();
                if ($existing) {
                    $user = User::whereKey($existing->user_id)->lockForUpdate()->first();
                    if (! $user || $user->role !== 'customer') {
                        throw new GoogleSignInException('Staff and owner accounts must use their email and password.');
                    }
                    $this->matchingEmail($user, $identity['email']);

                    return [$user, false];
                }
                if (User::whereRaw('LOWER(email) = ?', [$identity['email']])->exists()) {
                    throw new GoogleSignInException('An account already uses this email. Sign in with your password, then link Google from My Account. Staff and owners must keep using email and password.');
                }
                if (StaffInvitation::whereRaw('LOWER(email) = ?', [$identity['email']])->exists()) {
                    throw new GoogleSignInException('This email has a staff setup. Ask the store owner to finish its verification, then sign in with email and password.');
                }
                $user = new User;
                $user->forceFill(['name' => $identity['name'], 'email' => $identity['email'], 'role' => 'customer',
                    'password' => Str::random(64), 'email_verified_at' => now()])->save();
                GoogleIdentity::create(['subject' => $identity['subject'], 'user_id' => $user->id]);

                return [$user, true];
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw new GoogleSignInException('An account or Google link was created at the same time. Start sign-in again, or use your existing email and password.');
            }
            throw $exception;
        }
    }

    protected function provider(Request $request): GoogleOAuthProvider
    {
        return (new GoogleOAuthProvider($request, config('services.google.client_id'), config('services.google.client_secret'),
            config('services.google.redirect'), ['timeout' => 12, 'connect_timeout' => 5, 'allow_redirects' => false]))
            ->enablePKCE()->setScopes(['openid', 'email', 'profile'])->with(['prompt' => 'select_account']);
    }

    private function matchingEmail(User $user, string $email): void
    {
        if (Str::lower(trim($user->email)) !== $email) {
            throw new GoogleSignInException('Your Google email must match your fixed store account email. Use email and password, or password recovery, if your Google email has changed.');
        }
    }
}
