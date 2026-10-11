<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function form(string $mode = 'login')
    {
        return view('auth', compact('mode'));
    }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data)) {
            return back()->withErrors(['email' => 'The email or password is incorrect.'])->onlyInput('email');
        }
        $r->session()->regenerate();

        ActivityRecorder::mark($r, 'auth.login', $r->user());

        return redirect()->intended($r->user()->role === 'customer' ? route('store') : route('admin.dashboard'));
    }

    public function register(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:254|unique:users', 'password' => ['required', 'confirmed', Password::min(10)]]);
        $user = User::create($data);
        Auth::login($user);
        $r->session()->regenerate();
        ActivityRecorder::mark($r, 'auth.registered', $user);

        return redirect()->route('store');
    }

    public function logout(Request $r)
    {
        $user = $r->user();
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        ActivityRecorder::mark($r, 'auth.logout', $user);

        return redirect()->route('store');
    }
}
