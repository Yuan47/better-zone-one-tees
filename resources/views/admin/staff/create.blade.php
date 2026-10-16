@extends('layout')
@section('title','Add staff account')
@section('crumb','Staff accounts / Add staff')
@section('content')
<div class="page-heading"><a class="text-link" href="{{ route('admin.staff.index') }}">← Staff accounts</a><span class="eyebrow">STEP 1 OF 2</span><h1>Add a staff account</h1><p class="muted">Enter their details, then verify the code sent to their email. Access starts only after verification.</p></div>
<form class="panel stack faq-editor" method="post" action="{{ route('admin.staff.store') }}" autocomplete="off">@csrf
@if(!$setupReady)<div class="notice" role="status">Email delivery is not configured yet. You can save these details as a pending setup and send the code once email is ready.</div>@endif
<label for="staff-name">Staff name<input id="staff-name" name="name" type="text" maxlength="100" required value="{{ old('name') }}" autocomplete="name"></label>
<label for="staff-email">Staff email<input id="staff-email" name="email" type="email" maxlength="254" required value="{{ old('email') }}" autocomplete="email"><small>Use the staff member’s own email address. Existing account emails cannot be reused.</small></label>
<label for="staff-password">Initial password<input id="staff-password" name="password" type="password" minlength="10" maxlength="72" required autocomplete="new-password"><small>Use at least 10 characters. Give the initial password to the staff member privately; they can change it in My Account.</small></label>
<label for="staff-password-confirmation">Confirm initial password<input id="staff-password-confirmation" name="password_confirmation" type="password" minlength="10" maxlength="72" required autocomplete="new-password"></label>
<div class="faq-actions"><button class="button dark">{{ $setupReady ? 'Save & send setup code →' : 'Save pending setup →' }}</button><a class="text-link" href="{{ route('admin.staff.index') }}">Cancel</a></div>
</form>
@endsection
