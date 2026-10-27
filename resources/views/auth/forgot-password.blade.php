@extends('layout')
@section('title', 'Forgot your password')
@section('content')
<div class="verification-layout">
<section class="panel verification-card" aria-labelledby="recovery-title">
<span class="eyebrow">ACCOUNT RECOVERY</span>
<div class="verification-icon" aria-hidden="true">✉</div>
<h1 id="recovery-title">Forgot your password?</h1>
<p>Enter your account email. We’ll send a link so you can choose a new password.</p>
@if(!$recoveryReady)
<div class="notice" role="status">Password recovery email is not available yet. Please contact the store for help.</div>
@endif
<form class="stack" method="post" action="{{ route('password.email') }}">@csrf
<label for="recovery-email">Email address
<input id="recovery-email" name="email" type="email" autocomplete="email" maxlength="254" required value="{{ old('email') }}" aria-describedby="recovery-help">
</label>
<p id="recovery-help" class="muted">The link expires after 60 minutes. Check your spam folder too.</p>
<button class="button dark" @disabled(!$recoveryReady)>Send reset link →</button>
</form>
<p>Back to <a class="text-link" href="{{ route('login') }}">Customer sign in</a> · <a class="text-link" href="{{ route('admin.login') }}">Staff & owner sign in</a></p>
</section>
</div>
@endsection
