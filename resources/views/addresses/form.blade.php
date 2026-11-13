@extends('layout')
@section('title', $address->exists ? 'Edit delivery address' : 'Add delivery address')
@section('content')
<link rel="stylesheet" href="{{ asset('address-book.css') }}?v={{ filemtime(public_path('address-book.css')) }}">
<script src="{{ asset('address-map.js') }}?v={{ filemtime(public_path('address-map.js')) }}" defer></script>
<div class="customer-account-shell">
    @include('partials.customer-account-nav')
    <section class="panel address-book">
        <div class="section-heading"><h1>{{ $address->exists ? 'Edit address' : 'Add address' }}</h1><a class="text-link" href="{{ route('account.addresses.index') }}">Back to addresses</a></div>
        <p class="muted">Fields marked <span class="required-mark" aria-hidden="true">*</span> are required.</p>
        <form class="stack" method="post" action="{{ $address->exists ? route('account.addresses.update', $address) : route('account.addresses.store') }}" id="address-book-form">
            @csrf @if($address->exists) @method('patch') @endif
            <div class="two-col">
                <label>Recipient name <span class="required-mark" aria-hidden="true">*</span><input name="recipient" required maxlength="100" autocomplete="name" value="{{ old('recipient', $address->recipient) }}"></label>
                <label>Mobile number <span class="required-mark" aria-hidden="true">*</span><input name="phone" type="tel" required inputmode="numeric" minlength="11" maxlength="11" pattern="09[0-9]{9}" autocomplete="tel-national" placeholder="09xxxxxxxxx" value="{{ old('phone', $address->phone) }}"><small class="muted">11 digits beginning with 09.</small></label>
            </div>
            <label>Street address <span class="required-mark" aria-hidden="true">*</span><input id="saved-street" name="street_address" required maxlength="200" autocomplete="street-address" placeholder="House / unit, building, street and barangay" value="{{ old('street_address', $address->street_address) }}"></label>
            <div class="two-col">
                <label>City <span class="required-mark" aria-hidden="true">*</span><input id="saved-city" name="city" required maxlength="100" autocomplete="address-level2" value="{{ old('city', $address->city) }}"></label>
                <label>Province <span class="required-mark" aria-hidden="true">*</span><input id="saved-province" name="province" required maxlength="100" autocomplete="address-level1" value="{{ old('province', $address->province) }}"></label>
            </div>
            <div class="two-col">
                <label>Postal code <span class="required-mark" aria-hidden="true">*</span><input id="saved-postal" name="postal_code" required maxlength="20" autocomplete="postal-code" value="{{ old('postal_code', $address->postal_code) }}"></label>
                <label>Country <span class="required-mark" aria-hidden="true">*</span><input id="saved-country" name="country" required readonly value="Philippines" autocomplete="country-name"></label>
            </div>
            <section class="address-pin-picker" aria-labelledby="address-pin-title">
                <h2 id="address-pin-title">Delivery entrance <span class="required-mark" aria-hidden="true">*</span></h2>
                <p>Tap the map or drag the pin to the entrance where the rider should deliver your order.</p>
                <button class="button" type="button" id="address-use-location">Use my current location</button>
                <p id="address-map-status" role="status"></p>
                <div id="address-book-map" aria-label="Map for choosing the delivery entrance" data-start-lat="{{ config('shop.lalamove.lat') ?: '14.5995' }}" data-start-lng="{{ config('shop.lalamove.lng') ?: '120.9842' }}"></div>
                <input type="hidden" name="lat" id="saved-lat" value="{{ old('lat', $address->lat) }}">
                <input type="hidden" name="lng" id="saved-lng" value="{{ old('lng', $address->lng) }}">
                <label class="address-pin-confirmation"><input type="checkbox" name="pin_confirmed" value="1" id="saved-pin-confirmed" required @checked(old('pin_confirmed'))><span>I confirm this pin marks the delivery entrance. <span class="required-mark" aria-hidden="true">*</span></span></label>
                <details><summary>Enter map coordinates</summary><p class="muted">Use this if the map cannot load.</p><div class="two-col"><label>Latitude<input type="number" id="saved-manual-lat" step="any" min="-90" max="90" value="{{ old('lat', $address->lat) }}"></label><label>Longitude<input type="number" id="saved-manual-lng" step="any" min="-180" max="180" value="{{ old('lng', $address->lng) }}"></label></div></details>
            </section>
            <div class="two-col"><label>Address label <span class="required-mark" aria-hidden="true">*</span><input name="label" required maxlength="40" value="{{ old('label', $address->label ?: 'Home') }}" list="address-labels"><datalist id="address-labels"><option value="Home"><option value="Work"></datalist></label><label class="address-default-option"><span><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $address->is_default))> Set as default address</span></label></div>
            <button class="button dark" id="save-address">Save address</button>
        </form>
    </section>
</div>
@endsection
