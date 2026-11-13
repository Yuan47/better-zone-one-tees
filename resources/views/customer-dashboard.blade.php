@extends('layout')
@section('title','My dashboard')
@section('content')
<section class="customer-dashboard" aria-labelledby="customer-dashboard-title">
    <div class="page-heading customer-dashboard-heading">
        <div><span class="eyebrow">YOUR ZONE ONE ACCOUNT</span><h1 id="customer-dashboard-title">My dashboard</h1><p class="muted">Welcome, {{ $user->name }}. Your orders, payments and favorites are here.</p></div>
        <a class="button" href="{{ route('store') }}">Continue shopping →</a>
    </div>

    <div class="customer-summary-grid">
        <a class="panel customer-summary-card" href="{{ route('orders') }}"><span>My Purchases</span><strong>{{ number_format($orderCount) }}</strong><small>View your complete order history →</small></a>
        <a class="panel customer-summary-card" href="{{ route('orders', ['status' => 'to_pay']) }}"><span>Open balance</span><strong>₱{{ number_format($outstandingBalance / 100, 2) }}</strong><small>{{ number_format($openInvoiceCount) }} open {{ $openInvoiceCount === 1 ? 'invoice' : 'invoices' }} awaiting payment</small></a>
        <a class="panel customer-summary-card" href="{{ route('store', ['wishlist'=>1]) }}"><span>Favorites / wishlist</span><strong>{{ number_format($favoriteCount) }}</strong><small>View your saved clothing styles →</small></a>
    </div>

    <section class="customer-recent-orders" aria-labelledby="recent-orders-title">
        <div class="section-heading"><div><h2 id="recent-orders-title">Recent orders</h2><p class="muted">Open an order for its invoice, payment records and delivery updates.</p></div><a class="text-link" href="{{ route('orders') }}">Full order history →</a></div>
        <div class="panel purchase-list-panel">
            @if($recentOrders->count())
                @include('partials.customer-purchase-table', ['purchaseOrders' => $recentOrders])
            @else
                <div class="empty-state"><h3>No orders yet</h3><p>Your online purchases and walk-in orders linked to this account will appear here.</p><a class="button dark" href="{{ route('store') }}">Browse clothing</a></div>
            @endif
        </div>
    </section>

    <section class="panel customer-account-shortcut" aria-labelledby="customer-account-title"><div><h2 id="customer-account-title">My Account</h2><p class="muted">Update your profile photo, username, delivery address or password.</p></div><a class="button" href="{{ route('account') }}">Manage my account →</a></section>
</section>
@endsection
