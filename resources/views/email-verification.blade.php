@extends('layout')
@section('title','Verify your email')
@section('content')
@php
    $verified = $user->email_verified_at !== null;
    $resendAt = $verification['resend_at'] ?? null;
    $waiting = $resendAt && \Carbon\CarbonImmutable::parse($resendAt)->isFuture();
    $canEnter = $verificationReady && in_array($verification['status'] ?? '', ['pending'], true);
@endphp
<div class="verification-layout">
<section class="panel verification-card" aria-labelledby="verification-title">
<span class="eyebrow">YOUR ACCOUNT</span>
<div class="verification-icon" aria-hidden="true">{{ $verified ? '✓' : '✉' }}</div>
<h1 id="verification-title">{{ $verified ? 'Your email is verified.' : 'Check your email.' }}</h1>
@if($verified)
<p>You’re ready to continue with Zone One Tee’s.</p>
<a class="button dark" href="{{ $user->role==='customer' ? route('store') : route('admin.dashboard') }}">Continue →</a>
@else
<p>Verify that <strong class="verification-email">{{ $user->email }}</strong> belongs to you.</p>
@if(!$verificationRequired)
<div class="notice" role="status">Email verification is being prepared. The store has not enabled it yet.</div>
@elseif(!$verificationReady)
<div class="notice" role="status">Email delivery is not available yet. Please contact the store for help.</div>
@else
<p class="muted">Enter the latest six-digit code from your email. Each code expires after 10 minutes.</p>
@if(($verification['status'] ?? '') === 'expired')
<div class="notice" role="status">Your code has expired. Request a new one below.</div>
@elseif(($verification['status'] ?? '') === 'locked')
<div class="notice" role="status">This code has reached its attempt limit. Request a new one when the waiting time ends.</div>
@elseif(($verification['status'] ?? '') === 'failed')
<div class="notice" role="status">The last email could not be sent. Request a new code when the waiting time ends.</div>
@endif
<form class="stack" method="post" action="{{ route('verification.verify') }}">@csrf
<label for="verification-code">Verification code
<input id="verification-code" class="verification-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required placeholder="000000" aria-describedby="verification-code-help" @disabled(!$canEnter)>
</label>
<small id="verification-code-help" class="muted">{{ $canEnter ? 'Use the most recent code. '.($verification['attempts_remaining'] ?? 0).' attempts remaining.' : 'Request a code before verifying your email.' }}</small>
<button class="button dark" @disabled(!$canEnter)>Verify email →</button>
</form>
<form class="verification-resend" method="post" action="{{ route('verification.send') }}">@csrf
<button class="button secondary" id="verification-resend" data-resend-at="{{ $resendAt }}" data-initial-label="{{ ($verification['status'] ?? '')==='none' ? 'Send verification code' : 'Send a new code' }}" @disabled($waiting)>{{ ($verification['status'] ?? '')==='none' ? 'Send verification code' : ($waiting ? 'Please wait to resend' : 'Send a new code') }}</button>
<p class="muted" id="verification-resend-help" aria-live="polite">Check your spam folder too. A new code replaces the previous one.</p>
</form>
<script src="{{ asset('email-verification.js') }}?v={{ filemtime(public_path('email-verification.js')) }}" defer></script>
@endif
<div class="verification-help"><p class="muted">Signed in with the wrong email? Sign out and use the correct account. For an incorrect account email, contact the store.</p><form method="post" action="{{ route('logout') }}">@csrf<button class="text-button">Sign out</button></form></div>
<a class="text-link" href="{{ route('store') }}">Continue browsing</a>
@endif
</section>
</div>
@endsection
