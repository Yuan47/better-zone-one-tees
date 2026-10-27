@extends('layout')
@section('title', 'Choose a new password')
@section('content')
<div class="verification-layout">
<section class="panel verification-card" aria-labelledby="reset-title">
<span class="eyebrow">ACCOUNT RECOVERY</span>
<div class="verification-icon" aria-hidden="true">↻</div>
<h1 id="reset-title">Choose a new password.</h1>
<p>Use a password with at least 10 characters that you haven’t used elsewhere.</p>
@if(!$recoveryEnabled)
<div class="notice" role="status">Password recovery is not available yet. Please contact the store for help.</div>
@endif
<form class="stack" method="post" action="{{ route('password.update') }}">@csrf
<input type="hidden" name="token" value="{{ $resetToken }}">
<label for="reset-email">Account email
<input id="reset-email" name="email" type="email" autocomplete="email" maxlength="254" required value="{{ $resetEmail }}">
</label>
<label for="reset-password">New password
<input id="reset-password" name="password" type="password" autocomplete="new-password" minlength="10" maxlength="255" required>
</label>
<label for="reset-password-confirmation">Confirm new password
<input id="reset-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="10" maxlength="255" required>
</label>
<button class="button dark" @disabled(!$recoveryEnabled || $resetToken === '')>Save new password →</button>
</form>
<p class="muted">This link works once. Resetting your password signs out your other sessions.</p>
<p><a class="text-link" href="{{ route('password.request') }}">Request a new link</a></p><p>Back to <a class="text-link" href="{{ route('login') }}">Customer sign in</a> · <a class="text-link" href="{{ route('admin.login') }}">Staff & owner sign in</a></p>
</section>
</div>
@endsection
