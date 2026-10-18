@if($user->role==='customer' && app(\App\Services\GoogleSignIn::class)->ready())
<section class="panel stack" aria-labelledby="google-account-heading">
<h2 id="google-account-heading">Google sign-in</h2>
@if(\App\Models\GoogleIdentity::where('user_id',$user->id)->exists())
<p><span class="badge">Google linked</span></p><p class="muted">You can use Google to sign in to this customer account. Your store email stays {{ $user->email }} and must match your Google email.</p>
<p class="muted">Created your account with Google? <a class="text-link" href="{{ route('password.request') }}">Use password recovery</a> to set your first password for email sign-in.</p>
@else
<p class="muted">Link your Google account for easier sign-in. Choose the Google account with the same verified email: {{ $user->email }}.</p>
<form class="stack" method="post" action="{{ route('google.link') }}">@csrf
<label>Confirm your current password<input type="password" name="current_password" required maxlength="255" autocomplete="current-password"></label>
<button class="button" type="submit">Link Google account</button>
</form>
@endif
</section>
@endif
