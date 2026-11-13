@extends('layout')
@section('title', 'My addresses')
@section('content')
<link rel="stylesheet" href="{{ asset('address-book.css') }}?v={{ filemtime(public_path('address-book.css')) }}">
<div class="customer-account-shell">
    @include('partials.customer-account-nav')
    <section class="panel address-book">
        <div class="section-heading">
            <div><h1>My addresses</h1><p class="muted">Choose where your clothing should be delivered.</p></div>
            <a class="button dark" href="{{ route('account.addresses.create') }}">＋ Add address</a>
        </div>
        @forelse($addresses as $address)
            <article class="saved-address">
                <div class="saved-address-heading"><h2>{{ $address->recipient }}</h2><span>{{ $address->phone ?: 'Add a mobile number' }}</span></div>
                <p>{{ $address->street_address }}<br>{{ $address->city }}, {{ $address->province }} {{ $address->postal_code }}<br>{{ $address->country }}</p>
                <div class="saved-address-tags"><span class="badge">{{ $address->label }}</span>@if($address->is_default)<span class="badge address-default">Default</span>@endif</div>
                @if(!$address->hasDeliveryPin())<p class="address-pin-warning">Select the delivery pin before using this address for Lalamove.</p>@endif
                <div class="saved-address-actions">
                    <a class="button small" href="{{ route('account.addresses.edit', $address) }}">Edit</a>
                    @if(!$address->is_default)
                        <form method="post" action="{{ route('account.addresses.default', $address) }}">@csrf @method('patch')<button class="button small">Set as default</button></form>
                    @endif
                    <form method="post" action="{{ route('account.addresses.destroy', $address) }}">@csrf @method('delete')<button class="text-button">Remove</button></form>
                </div>
            </article>
        @empty
            <div class="empty-note"><p>You have no saved addresses yet.</p><p>Add your delivery details and select a map pin to reuse them at checkout.</p></div>
        @endforelse
    </section>
</div>
@endsection
