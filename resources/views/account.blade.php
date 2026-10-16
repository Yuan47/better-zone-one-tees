@extends('layout')
@section('title','My Account')
@section('crumb','My Account')
@section('content')
@php
    $usernameBase = \Illuminate\Support\Str::slug($user->name, '');
    $suggestedUsername = $user->username ?? (strlen($usernameBase) >= 3 ? substr($usernameBase, 0, 25) : 'user').$user->id;
@endphp
<div class="page-heading"><span class="eyebrow">YOUR PROFILE</span><h1>My Account</h1><p class="muted">Keep your profile and delivery details up to date.</p></div>
<div class="checkout-grid account-grid">
<form class="panel stack" method="post" action="{{ route('account.update') }}" enctype="multipart/form-data">@csrf @method('patch')
<h2>Profile</h2>
<div class="account-photo">
@if($user->avatar_path)<img class="account-avatar" src="{{ asset($user->avatar_path) }}" alt="Your profile photo">@else<div class="account-avatar account-avatar-placeholder" aria-label="No profile photo">{{ mb_strtoupper(mb_substr($user->name,0,1)) }}</div>@endif
<label>Upload profile photo<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"><small class="muted">JPG, PNG, or WebP · Up to 2 MB</small></label>
</div>
@if($user->avatar_path)<label class="checkbox-label"><span><input type="checkbox" name="remove_avatar" value="1"> Remove current photo</span></label>@endif
<div class="two-col"><label>Username<input name="username" required minlength="3" maxlength="40" pattern="[a-z0-9_.\-]+" value="{{ old('username',$suggestedUsername) }}" autocomplete="username"><small class="muted">Lowercase letters, numbers, dots, underscores, or hyphens.</small></label><label>Full name<input name="name" required maxlength="100" value="{{ old('name',$user->name) }}" autocomplete="name"></label></div>
<label>Email<input type="email" value="{{ $user->email }}" readonly aria-readonly="true" autocomplete="email"><small class="muted">Your sign-in email is fixed.</small></label>
<div class="verification-account"><span class="badge">{{ $user->email_verified_at ? 'Email verified' : 'Email not verified' }}</span>@if(!$user->email_verified_at)<a class="text-link" href="{{ route('verification.notice') }}">Verify email →</a>@endif</div>
@if($user->role==='customer')
<h2>Delivery address</h2><p class="muted">Saved details will appear at checkout. Enter a complete address when adding or changing it.</p>
<label>Mobile number<input type="tel" name="phone" pattern="\+?[0-9]{10,15}" maxlength="16" value="{{ old('phone',$user->phone) }}" autocomplete="tel" placeholder="09xxxxxxxxx"></label>
<label>Street Address<input name="street_address" maxlength="200" value="{{ old('street_address',$user->street_address) }}" autocomplete="address-line1" placeholder="House / unit number, street, barangay"></label>
<div class="two-col"><label>City<input name="city" maxlength="100" value="{{ old('city',$user->city) }}" autocomplete="address-level2"></label><label>Province<input name="province" maxlength="100" value="{{ old('province',$user->province) }}" autocomplete="address-level1"></label></div>
<div class="two-col"><label>Postal code<input name="postal_code" maxlength="20" value="{{ old('postal_code',$user->postal_code) }}" autocomplete="postal-code"></label><label>Country<input name="country" maxlength="100" value="{{ old('country',$user->country ?? 'Philippines') }}" autocomplete="country-name"></label></div>
<details><summary>Delivery coordinates (optional)</summary><p class="muted">Lalamove needs exact coordinates for quotations. You can also provide these at checkout.</p><div class="two-col"><label>Latitude<input type="number" name="lat" min="-90" max="90" step="any" value="{{ old('lat',$user->lat) }}"></label><label>Longitude<input type="number" name="lng" min="-180" max="180" step="any" value="{{ old('lng',$user->lng) }}"></label></div></details>
@endif
<button class="button dark">Save account</button></form>
<form class="panel stack" method="post" action="{{ route('account.password') }}">@csrf @method('patch')
<h2>Change Password</h2><p class="muted">Use at least 10 characters. Changing your password signs out your other browser sessions.</p>
<label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
<label>New password<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
<label>Confirm new password<input type="password" name="password_confirmation" required minlength="10" autocomplete="new-password"></label>
<button class="button dark">Change password</button>
</form></div>
@endsection
