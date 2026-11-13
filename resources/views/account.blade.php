@extends('layout')
@section('title','My Account')
@section('crumb','My Account')
@section('content')
<link rel="stylesheet" href="{{ asset('address-book.css') }}?v={{ filemtime(public_path('address-book.css')) }}">
@php
    $usernameBase = \Illuminate\Support\Str::slug($user->name, '');
    $suggestedUsername = $user->username ?? (strlen($usernameBase) >= 3 ? substr($usernameBase, 0, 25) : 'user').$user->id;
@endphp
@if($user->role==='customer')<div class="customer-account-shell">@include('partials.customer-account-nav')<div class="customer-account-main">@endif
<div class="page-heading"><h1>My Account</h1><p class="muted">Manage your profile and account security.</p></div>
<div class="checkout-grid account-grid">
<form class="panel stack" method="post" action="{{ route('account.update') }}" enctype="multipart/form-data">@csrf @method('patch')
<h2>Profile</h2>
<div class="account-photo">
@if($user->avatar_path)<img class="account-avatar" src="{{ asset($user->avatar_path) }}" alt="Your profile photo">@else<div class="account-avatar account-avatar-placeholder" aria-label="No profile photo">{{ mb_strtoupper(mb_substr($user->name,0,1)) }}</div>@endif
<label>Upload profile photo<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"><small class="muted">JPG, PNG, or WebP · Up to 2 MB</small></label>
</div>
@if($user->avatar_path)<label class="checkbox-label"><span><input type="checkbox" name="remove_avatar" value="1"> Remove current photo</span></label>@endif
<div class="two-col"><label>Username<input name="username" required minlength="3" maxlength="40" pattern="[a-z0-9_.\-]+" value="{{ \App\Support\FormInput::old('username',$suggestedUsername) }}" autocomplete="username"><small class="muted">Lowercase letters, numbers, dots, underscores, or hyphens.</small></label><label>Full name<input name="name" required maxlength="100" value="{{ \App\Support\FormInput::old('name',$user->name) }}" autocomplete="name"></label></div>
<label>Email<input type="email" value="{{ $user->email }}" readonly aria-readonly="true" autocomplete="email"><small class="muted">Your sign-in email is fixed.</small></label>
<div class="verification-account"><span class="badge">{{ $user->email_verified_at ? 'Email verified' : 'Email not verified' }}</span>@if(!$user->email_verified_at)<a class="text-link" href="{{ route('verification.notice') }}">Verify email →</a>@endif</div>
@if($user->role==='customer')
<label>Mobile number<input type="tel" name="phone" pattern="09[0-9]{9}" inputmode="numeric" minlength="11" maxlength="11" value="{{ \App\Support\FormInput::old('phone',app(\App\Services\CustomerAddresses::class)->localPhone($user->phone)) }}" autocomplete="tel-national" placeholder="09xxxxxxxxx"><small class="muted">11 digits beginning with 09.</small></label>
<p><a class="text-link" href="{{ route('account.addresses.index') }}">Manage delivery addresses and map pins →</a></p>
@endif
<button class="button dark">Save account</button></form>
<div class="stack"><form class="panel stack" id="account-password" method="post" action="{{ route('account.password') }}">@csrf @method('patch')
<h2>Change Password</h2><p class="muted">Use at least 10 characters. Changing your password signs out your other browser sessions.</p>
<label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
<label>New password<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
<label>Confirm new password<input type="password" name="password_confirmation" required minlength="10" autocomplete="new-password"></label>
<button class="button dark">Change password</button>
</form>
@include('partials.google-account')
</div></div>
@if($user->role==='customer')</div></div>@endif
@endsection
