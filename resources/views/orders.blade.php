@extends('layout')
@section('title','My orders')
@section('content')
<div class="page-heading"><span class="eyebrow">YOUR ZONE ONE JOURNEY</span><h1>My orders</h1><p class="muted">Payment and fulfillment updates, all in one place.</p></div>
<div class="order-grid">
@forelse($orders as $order)
@php($received = $order->paidAmount())
@php($balance = $order->outstandingBalance())
<article class="panel"><div class="section-heading"><h3>{{ $order->number }}</h3><span class="badge">{{ $order->status === 'cancelled' ? 'Cancelled' : ($order->payment_status === 'paid' ? $order->customerDeliveryLabel() : ucfirst(str_replace('_',' ',$order->payment_status))) }}</span></div>
<p class="muted">{{ $order->created_at->format('M d, Y') }} · {{ $order->sales_channel === 'walk_in' ? 'Walk-in' : 'Online' }} · {{ $order->quantity }} pieces · {{ ucfirst($order->tier) }} pricing</p><p>{{ $order->items->pluck('name')->unique()->join(', ') }}</p>
<p>Received: ₱{{ number_format($received/100,2) }} · Outstanding: ₱{{ number_format($balance/100,2) }}</p>
@if($balance && $order->due_at)
<p class="muted">Due {{ $order->due_at->format('M d, Y · g:i A') }}
    @if($order->status !== 'cancelled' && $order->invoiceOverdue())
    · Overdue
    @endif
</p>
@endif
<div class="section-heading"><b>₱{{ number_format($order->total/100,2) }}</b><a class="text-link" href="{{ route('orders.show',$order) }}">View order →</a></div></article>
@empty
<div class="empty-state"><h2>Your first order starts here.</h2><p>Explore the collection to find your everyday essentials.</p><a class="button dark" href="{{ route('store') }}">Shop the collection</a></div>
@endforelse
</div>{{ $orders->links('pagination') }}
@endsection
