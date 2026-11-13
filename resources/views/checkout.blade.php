@extends('layout')
@section('title', 'Checkout')
@section('content')
<div class="page-heading"><h1>Checkout</h1><p class="muted">Review your delivery details and total before paying.</p></div>
<ol class="checkout-progress" aria-label="Checkout progress"><li aria-current="step">Delivery details</li><li>Review total</li><li>Pay securely</li><li>Order confirmation</li></ol>
@if(!$payReady)<div class="notice">Online payment is temporarily unavailable. Your cart is saved.</div>@endif
<form action="{{ route('checkout.place') }}" method="post" id="checkout-form" class="checkout-grid">
    @csrf
    <input type="hidden" name="checkout_key" value="{{ $checkoutKey }}">
    <input type="hidden" name="payment_option" value="full">
    <div class="panel stack">
        <h2>Contact & delivery</h2>
        <p class="required-note"><span class="required-mark" aria-hidden="true">*</span> Required information</p>
        @include('partials.checkout-address-selector')
        <div class="two-col">
            <label>Recipient name <span class="required-mark" aria-hidden="true">*</span><input name="recipient" required maxlength="100" value="{{ old('recipient', auth()->user()->name) }}" autocomplete="name"></label>
            <label>Mobile number <span class="required-mark" aria-hidden="true">*</span><input name="phone" type="tel" inputmode="numeric" required pattern="09[0-9]{9}" minlength="11" maxlength="11" placeholder="09xxxxxxxxx" value="{{ old('phone', \App\Rules\PhilippineMobileNumber::local(auth()->user()->phone)) }}" autocomplete="tel"><small>11 digits, beginning with 09.</small></label>
        </div>
        <label>Receive your order <span class="required-mark" aria-hidden="true">*</span><select id="delivery-method" name="delivery_method" required><option value="pickup" @selected(old('delivery_method') === 'pickup')>Store pickup · Free</option><option value="lalamove" @selected(old('delivery_method') === 'lalamove') @disabled(!$deliveryReady)>Lalamove {{ config('shop.lalamove.mode') === 'sandbox' ? 'sandbox' : '' }} delivery{{ !$deliveryReady ? ' · Unavailable' : '' }}</option></select></label>
        <div id="delivery-fields" hidden>
            <h3>Delivery address</h3>
            <label>Street address <span class="required-mark" aria-hidden="true">*</span><input name="street_address" id="address-street" maxlength="200" value="{{ old('street_address', auth()->user()->street_address) }}" autocomplete="street-address" placeholder="House / unit, building, street, barangay"></label>
            <div class="two-col">
                <label>City <span class="required-mark" aria-hidden="true">*</span><input name="city" id="address-city" maxlength="100" value="{{ old('city', auth()->user()->city) }}" autocomplete="address-level2"></label>
                <label>Province <span class="required-mark" aria-hidden="true">*</span><input name="province" id="address-province" maxlength="100" value="{{ old('province', auth()->user()->province) }}" autocomplete="address-level1"></label>
                <label>Postal code <span class="required-mark" aria-hidden="true">*</span><input name="postal_code" id="address-postal" maxlength="20" value="{{ old('postal_code', auth()->user()->postal_code) }}" autocomplete="postal-code"></label>
                <label>Country <span class="required-mark" aria-hidden="true">*</span><input name="country" id="address-country" maxlength="100" value="{{ old('country', auth()->user()->country ?? 'Philippines') }}" autocomplete="country-name"></label>
            </div>
            <h3>Delivery pin <span class="required-mark" aria-hidden="true">*</span></h3>
            <p class="muted">Tap the map or drag the pin to the entrance where the rider should deliver. Confirm it before requesting the fee.</p>
            <button type="button" id="locate-button" class="button">Use my current location</button>
            <p id="pin-status" role="status">Select your delivery pin.</p>
            <div id="delivery-map" aria-label="Delivery map: tap to select your destination" data-start-lat="{{ config('shop.lalamove.lat') ?: '14.5995' }}" data-start-lng="{{ config('shop.lalamove.lng') ?: '120.9842' }}"></div>
            <small>Map tiles © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>. Device location is requested when you choose the location button.</small>
            <details class="coordinate-details"><summary>Enter a known map coordinate</summary><div class="two-col"><label>Latitude <span class="required-mark" aria-hidden="true">*</span><input id="delivery-lat" name="lat" type="number" step="any" min="-90" max="90" value="{{ old('lat', auth()->user()->lat) }}"></label><label>Longitude <span class="required-mark" aria-hidden="true">*</span><input id="delivery-lng" name="lng" type="number" step="any" min="-180" max="180" value="{{ old('lng', auth()->user()->lng) }}"></label></div></details>
            <label class="checkbox-label delivery-pin-confirmation"><input type="checkbox" id="pin-confirmed" name="pin_confirmed" value="1"><span>I confirm the pin matches my delivery entrance. <span class="required-mark" aria-hidden="true">*</span></span></label>
            <button type="button" id="quote-button" class="button" data-url="{{ route('checkout.quote') }}">Get delivery fee</button>
            <p id="quote-result" role="status"></p>
        </div>
        <p class="mini-note">Pay the full invoice through PayMongo. Packing starts after payment is confirmed.</p>
        <label class="checkbox-label terms-acceptance"><input type="checkbox" name="terms" value="1" required @checked(old('terms'))><span>I agree to the <a class="text-link" href="{{ route('terms') }}" target="_blank" rel="noopener">Terms and Conditions</a>, including the payment, delivery and return/refund process. <span class="required-mark" aria-hidden="true">*</span></span></label>
    </div>
    <aside class="panel order-summary">
        <h2>Order summary</h2>
        <p>{{ number_format($summary['quantity']) }} pieces · {{ $summary['label'] }} pricing</p>
        <dl>
            <div><dt>Clothing</dt><dd>₱{{ number_format($summary['base'] / 100, 2) }}</dd></div>
            @if($summary['discount'])<div class="saving"><dt>Bulk discount · 5%</dt><dd>−₱{{ number_format($summary['discount'] / 100, 2) }}</dd></div>@endif
            <div><dt>Delivery</dt><dd id="shipping-value">Free pickup</dd></div>
            <div class="total"><dt>Total to pay</dt><dd id="checkout-total" data-subtotal="{{ $summary['subtotal'] }}" data-invoice-total="{{ $summary['subtotal'] }}">₱{{ number_format($summary['subtotal'] / 100, 2) }}</dd></div>
        </dl>
        <button id="pay-button" class="button dark" @disabled(!$payReady)>Continue to payment →</button>
        <small>{{ config('shop.paymongo.mode') === 'test' ? 'TEST payment · Use the provider simulator.' : 'Secure PayMongo checkout.' }}</small>
        <a class="text-link" href="{{ route('cart') }}">Return to cart</a>
    </aside>
</form>
@endsection
