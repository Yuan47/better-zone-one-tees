<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CustomerIdleTimeout;
use App\Models\User;
use App\Rules\AccountPasswordSize;
use App\Services\AccountNavigation;
use App\Services\ActivityRecorder;
use App\Services\CustomerSessions;
use App\Services\EmailVerificationCodes;
use App\Services\InstallmentCredit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function form(Request $r, string $mode = 'login')
    {
        $workspace = $r->routeIs('admin.login', 'admin.login.submit');
        $switchRequired = $r->user() !== null;
        if ($switchRequired && AccountNavigation::workspace($r->user()) === $workspace) {
            return redirect()->route(AccountNavigation::homeRoute($r->user()));
        }
        if ($mode === 'login' && ! $workspace && $r->boolean('session_expired')) {
            session()->now('notice', CustomerIdleTimeout::NOTICE);
        }

        return response()->view('auth', compact('mode', 'workspace', 'switchRequired'))->header('Cache-Control', 'no-store, private');
    }

    public function login(Request $r)
    {
        $workspace = $r->routeIs('admin.login.submit');
        $portal = $workspace ? 'admin.login' : 'login';
        if ($r->user()) {
            return AccountNavigation::workspace($r->user()) === $workspace
                ? redirect()->route(AccountNavigation::homeRoute($r->user()))
                : redirect()->route($portal)->withErrors(['email' => 'Sign out of your current account before using another sign-in page.']);
        }
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        // The portal chooses permitted roles on the server; submitted role fields are ignored.
        if (! Auth::attempt($data + ['role' => $workspace ? ['staff', 'admin'] : 'customer'])) {
            return redirect()->route($portal)->withErrors(['email' => 'The email or password is incorrect.'])->onlyInput('email');
        }
        $r->session()->regenerate();
        if ($r->user()->role === 'customer') {
            app(CustomerSessions::class)->start($r);
        }

        ActivityRecorder::mark($r, 'auth.login', $r->user());
        if (app(InstallmentCredit::class)->alertsFor($r->user())->isNotEmpty()) {
            $r->session()->flash('payment_alert', true);
        }
        $destination = AccountNavigation::destination($r, $r->session()->pull('url.intended')) ?? route(AccountNavigation::homeRoute($r->user()));
        if (config('auth.email_verification.required') === true && $r->user()->email_verified_at === null) {
            $r->session()->put('email_verification.return_to', $destination);
            return redirect()->route('verification.notice');
        }

        return redirect($destination);
    }

    public function register(Request $r)
    {
        if (is_string($r->input('username'))) {
            $r->merge(['username' => Str::lower(trim($r->input('username')))]);
        }
        if (is_string($r->input('name'))) {
            $r->merge(['name' => trim($r->input('name'))]);
        }
        $data = $r->validate([
            'name' => ['bail', 'required', 'string', 'max:100'],
            'username' => ['bail', 'required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9_.-]+$/', 'unique:users,username'],
            'email' => 'required|email|max:254|unique:users',
            'password' => ['required', 'string', 'confirmed', Password::min(10), new AccountPasswordSize],
        ]);
        try {
            $user = User::create($data);
        } catch (UniqueConstraintViolationException $exception) {
            // The unique indexes also protect accounts created after validation finishes.
            $r->validate(['username' => 'unique:users,username', 'email' => 'unique:users,email']);
            throw $exception;
        }
        Auth::login($user);
        $r->session()->regenerate();
        app(CustomerSessions::class)->start($r);
        ActivityRecorder::mark($r, 'auth.registered', $user);

        $codes = app(EmailVerificationCodes::class);
        if ($codes->required()) {
            return redirect()->route('verification.notice')->with('notice', EmailVerificationController::sendMessage($codes->send($user)));
        }

        return redirect()->route('store');
    }

    public function logout(Request $r)
    {
        $user = $r->user();
        $destination = match ($r->input('sign_in_portal')) {
            'workspace' => 'admin.login',
            'customer' => 'login',
            default => AccountNavigation::workspace($user) ? 'admin.login' : 'store',
        };
        if ($user?->role === 'customer') {
            try {
                app(CustomerSessions::class)->end($r);
            } catch (\Throwable $exception) {
                // Signing out must still discard the browser session during a cache outage.
                report($exception);
            }
        }
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        ActivityRecorder::mark($r, 'auth.logout', $user);

        return redirect()->route($destination);
    }
}
