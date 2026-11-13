@extends('layout')
@section('title','My Purchases')
@section('content')
<section class="customer-purchases" aria-labelledby="purchases-title">
    <div class="page-heading purchase-heading"><div><h1 id="purchases-title">My Purchases</h1><p class="muted">Find your orders, payments and delivery updates.</p></div><a class="button" href="{{ route('store') }}">Continue shopping</a></div>
    <nav class="purchase-tabs" aria-label="Filter purchases by status">
        @foreach($tabs as $tab)
            <a href="{{ route('orders', array_filter(['status' => $tab['status'], 'q' => $filters['q']])) }}" @if($filters['status'] === $tab['status']) aria-current="page" @endif>{{ $tab['label'] }} <span>{{ number_format($tab['count']) }}</span></a>
        @endforeach
    </nav>
    <form method="get" action="{{ route('orders') }}" class="purchase-search" role="search" aria-label="Search your purchases">
        <input type="hidden" name="status" value="{{ $filters['status'] }}">
        <label class="sr-only" for="purchase-search">Order number, product, size or color</label>
        <input type="search" id="purchase-search" name="q" value="{{ $filters['q'] }}" maxlength="100" placeholder="Search by order number, product, size or color">
        <button class="button" type="submit">Search</button>
        @if($filters['q'] !== '')<a class="text-link" href="{{ route('orders', ['status' => $filters['status']]) }}">Clear search</a>@endif
    </form>
    <div class="panel purchase-list-panel">
        @if($orders->count())
            @include('partials.customer-purchase-table', ['purchaseOrders' => $orders])
        @else
            <div class="empty-state"><h2>{{ $filters['status'] === 'all' && $filters['q'] === '' ? 'Your first order starts here.' : 'No matching purchases' }}</h2><p>{{ $filters['status'] === 'all' && $filters['q'] === '' ? 'Your online and account-linked walk-in purchases will appear here.' : 'Choose another status or search term.' }}</p><a class="button dark" href="{{ route('store') }}">Shop the collection</a></div>
        @endif
    </div>
    {{ $orders->links('pagination') }}
</section>
@endsection
