@extends('layout')
@section('title',$mode==='register'?'Create account':($workspace?'Staff & owner sign in':'Customer sign in'))
@section('content')
<div class="auth-layout"><div class="auth-intro"><span class="eyebrow">{{ $workspace?'YOUR STORE WORKSPACE':'YOUR EVERYDAY STARTS HERE' }}</span><h1>{{ $workspace?'Keep your store moving.':($mode==='login'?'Good to see you again.':'Make yourself at home.') }}</h1><p>@if($workspace)Manage orders, payments and inventory<br>from your store workspace.@else Save your favorites, find your fit,<br>and follow every order in one place.@endif</p><div class="auth-art"><x-shirt color="#a1b2a1"/></div></div><div class="panel auth-card"><h2>{{ $mode==='register'?'Create your account':($workspace?'Staff & owner sign in':'Customer sign in') }}</h2>
@if($switchRequired)
<p>You’re already signed in with {{ \App\Services\AccountNavigation::workspace(auth()->user())?'a staff or owner':'a customer' }} account. Sign out before using another account.</p>
<form method="post" action="{{ route('logout') }}" class="stack">@csrf<input type="hidden" name="sign_in_portal" value="{{ $workspace?'workspace':'customer' }}"><button class="button dark">Sign out to switch accounts</button></form>
<p><a class="text-link" href="{{ route(\App\Services\AccountNavigation::homeRoute(auth()->user())) }}">Continue with your current account →</a></p>
@else
<p class="muted">{{ $workspace?'Use your registered staff or owner email and password.':($mode==='login'?'Welcome back to Zone One Tee’s.':'A few details, and you’re ready to shop.') }}</p>
@if($workspace)<p class="muted">Staff accounts are created by the store owner and verified by email. Ask the store owner if you need an account.</p>@endif
<form method="post" class="stack" action="{{ $mode==='register'?route('register'):route($workspace?'admin.login.submit':'login.submit') }}">@csrf
@if($mode==='register')<label>Username<input name="username" autocomplete="username" required minlength="3" maxlength="40" pattern="[A-Za-z0-9_.\-]+" autocapitalize="none" spellcheck="false" aria-describedby="signup-username-hint" value="{{ old('username') }}"><small id="signup-username-hint" class="muted">3–40 letters, numbers, dots, underscores, or hyphens. Saved in lowercase.</small></label><label>Full name<input name="name" autocomplete="name" required maxlength="100" value="{{ old('name') }}"></label>@endif
<label>Email address<input name="email" type="email" autocomplete="email" required value="{{ old('email') }}"></label><label>Password<input name="password" type="password" autocomplete="{{ $mode==='login'?'current-password':'new-password' }}" required @if($mode==='register') minlength="10" @endif></label>
@if($mode==='register')<label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="10"></label>@if(config('auth.email_verification.required'))<p class="muted">We’ll email a six-digit verification code after you create your account.</p>@endif @endif<button class="button dark">{{ $mode==='login'?'Sign in →':'Create account →' }}</button></form>
@if($mode==='login')<p><a class="text-link" href="{{ route('password.request') }}">Forgot your password?</a></p>@endif
@if(!$workspace && app(\App\Services\GoogleSignIn::class)->ready())
<form method="post" action="{{ route('google.start') }}" class="stack">@csrf<button class="button" type="submit">Continue with Google</button><small class="muted">For customer accounts. Staff and owners sign in with email and password.</small></form>
@endif
@if(!$workspace)<p>{{ $mode==='login'?'New here?':'Already have an account?' }} <a class="text-link" href="{{ $mode==='login'?route('register'):route('login') }}">{{ $mode==='login'?'Create an account':'Sign in' }}</a></p>@endif
@if(config('shop.demo') && app()->environment('local','testing'))<details class="demo-accounts"><summary>Local demo accounts</summary><p>@if($workspace)admin@zoneone.test<br>staff@zoneone.test @else customer@zoneone.test @endif</p><p>Password: <code>ZoneOneDemo!2026</code></p></details>@endif
@endif
<p><a class="text-link" href="{{ route($workspace?'login':'admin.login') }}">{{ $workspace?'Customer sign in':'Staff & owner sign in' }}</a></p></div></div>
@endsection
