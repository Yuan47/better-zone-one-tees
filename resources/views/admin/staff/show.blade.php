@extends('layout')
@section('title','Verify staff account')
@section('crumb','Staff accounts / Verify email')
@section('content')
@php
    $status = $setup['status'];
    $resendAt = $setup['resend_at'];
    $waiting = $resendAt && $resendAt->isFuture();
    $activated = $status === 'activated';
    $canEnter = $setupReady && $status === 'pending';
@endphp
<div class="page-heading"><a class="text-link" href="{{ route('admin.staff.index') }}">← Staff accounts</a><span class="eyebrow">{{ $activated ? 'SETUP COMPLETE' : 'STEP 2 OF 2' }}</span><h1>{{ $activated ? 'Staff account is ready.' : 'Verify the staff member’s email.' }}</h1></div>
<section class="panel stack faq-editor" aria-labelledby="staff-verification-heading">
<div><h2 id="staff-verification-heading">{{ $invitation->name }}</h2><p class="verification-email">{{ $invitation->email }}</p></div>
@if($activated)
<div class="notice" role="status">Email verified and staff access activated. They can now sign in with this email and the initial password you set.</div><p class="muted">The staff member can change their password in My Account or use password recovery.</p><a class="button dark" href="{{ route('admin.staff.index') }}">Back to staff accounts →</a>
@elseif($status==='email_taken')
<div class="notice" role="status">This email now belongs to an existing account. This pending setup cannot activate or change that account’s role.</div><a class="text-link" href="{{ route('admin.staff.create') }}">Set up a different staff email →</a>
@elseif(!$setupReady)
<div class="notice" role="status">Email delivery is not configured yet. These details are saved, and this staff member cannot sign in until their email is verified.</div><p class="muted">Once the store’s email setup is ready, open this page and send the verification code.</p>
@else
<p>Ask the staff member to check their email and give you the latest <strong>staff setup code</strong>. Enter it below to activate their account.</p><p class="muted">This code is only for staff setup. Never ask for their password recovery or personal account verification code.</p>
@if($status==='draft')<div class="notice" role="status">Send a code to start email verification.</div>@elseif($status==='expired')<div class="notice" role="status">The code has expired. Send a new one below.</div>@elseif($status==='locked')<div class="notice" role="status">Five incorrect attempts were made. Send a new code when the waiting time ends.</div>@elseif($status==='failed')<div class="notice" role="status">The email could not be sent. Send a new code when the waiting time ends.</div>@endif
<form class="stack" method="post" action="{{ route('admin.staff.verify',$invitation) }}">@csrf
<label for="staff-code">Staff setup code<input id="staff-code" class="verification-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required placeholder="000000" aria-describedby="staff-code-help" @disabled(!$canEnter)></label>
<small id="staff-code-help" class="muted">{{ $canEnter ? 'The latest code expires after 10 minutes. '.$setup['attempts_remaining'].' attempts remaining.' : 'Send a new code before activating this account.' }}</small>
<button class="button dark" @disabled(!$canEnter)>Verify email &amp; activate staff →</button>
</form>
<form class="verification-resend" method="post" action="{{ route('admin.staff.send',$invitation) }}">@csrf
<button class="button secondary" id="verification-resend" data-resend-at="{{ $resendAt?->toIso8601String() }}" data-initial-label="{{ $status==='draft' ? 'Send setup code' : 'Send a new setup code' }}" @disabled($waiting)>{{ $waiting ? 'Please wait to resend' : ($status==='draft' ? 'Send setup code' : 'Send a new setup code') }}</button>
<p class="muted" id="verification-resend-help" aria-live="polite">Check the spam folder too. A new code replaces the previous one.</p>
</form>
<script src="{{ asset('email-verification.js') }}?v={{ filemtime(public_path('email-verification.js')) }}" defer></script>
@endif
</section>
@endsection
