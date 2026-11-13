@php($savedAddresses = app(\App\Services\CustomerAddresses::class)->forUser(auth()->user()))
@if($savedAddresses->isNotEmpty())
<div class="checkout-saved-addresses">
    <label>Saved delivery address
        <select name="address_id" id="checkout-address-id">
            <option value="">Enter an address below</option>
            @foreach($savedAddresses as $savedAddress)
                <option value="{{ $savedAddress->id }}" @selected((string) \App\Support\FormInput::old('address_id', $savedAddresses->firstWhere('is_default', true)?->id) === (string) $savedAddress->id)
                    data-address="{{ json_encode($savedAddress->only(['recipient', 'phone', 'street_address', 'city', 'province', 'postal_code', 'country', 'lat', 'lng'])) }}">{{ $savedAddress->label }} — {{ $savedAddress->recipient }}{{ $savedAddress->is_default ? ' · Default' : '' }}</option>
            @endforeach
        </select>
    </label>
    <a class="text-link" href="{{ route('account.addresses.index') }}">Manage addresses</a>
</div>
@else
<p class="muted"><a class="text-link" href="{{ route('account.addresses.create') }}">Save a delivery address</a> to reuse your details and map pin.</p>
@endif
<script src="{{ asset('checkout-addresses.js') }}?v={{ filemtime(public_path('checkout-addresses.js')) }}" defer></script>
