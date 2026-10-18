<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CustomerIdleTimeout;
use App\Models\User;
use App\Rules\AccountPasswordSize;
use App\Services\ActivityRecorder;
use App\Services\CustomerSessions;
use App\Services\EmailVerificationCodes;
use App\Services\InstallmentCredit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function form(string $mode = 'login')
    {
        if ($mode === 'login' && request()->boolean('session_expired')) {
            session()->now('notice', CustomerIdleTimeout::NOTICE);
        }

        return view('auth', compact('mode'));
    }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data)) {
            return back()->withErrors(['email' => 'The email or password is incorrect.'])->onlyInput('email');
        }
        $r->session()->regenerate();
        if ($r->user()->role === 'customer') {
            app(CustomerSessions::class)->start($r);
        }

        ActivityRecorder::mark($r, 'auth.login', $r->user());
        if (app(InstallmentCredit::class)->alertsFor($r->user())->isNotEmpty()) {
            $r->session()->flash('payment_alert', true);
        }
        if (config('auth.email_verification.required') === true && $r->user()->email_verified_at === null) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended($r->user()->role === 'customer' ? route('store') : route('admin.dashboard'));
    }

    public function register(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:254|unique:users', 'password' => ['required', 'string', 'confirmed', Password::min(10), new AccountPasswordSize]]);
        $user = User::create($data);
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

        return redirect()->route('store');
    }
}
