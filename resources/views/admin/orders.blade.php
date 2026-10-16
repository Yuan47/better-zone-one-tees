@extends('layout')
@section('title','Orders')
@section('crumb','Orders')
@section('content')
<div class="page-heading"><span class="eyebrow">FROM CHECKOUT TO EVERYDAY</span><h1>Orders</h1><p class="muted">Online and walk-in invoices. Packing and delivery require full payment; deposits are shown separately.</p></div>
<p><a class="button dark" href="{{ route('admin.walk-in.create') }}">New walk-in invoice →</a></p>
<form class="filters">
    <label>Order or customer<input name="q" maxlength="100" value="{{ request('q') }}" placeholder="ZOT-… or recipient name"></label>
    <label>Delivery status<select name="fulfillment"><option value="">All stages</option>@foreach(\App\Models\Order::DELIVERY_STAGES as $stage=>$label)<option value="{{ $stage }}" @selected(request('fulfillment')===$stage)>{{ $label }}</option>@endforeach</select></label>
    <label>Payment<select name="payment_status"><option value="">All payments</option><option value="paid" @selected(request('payment_status')==='paid')>Confirmed paid</option><option value="partially_paid" @selected(request('payment_status')==='partially_paid')>Partially paid</option><option value="pending" @selected(request('payment_status')==='pending')>Unconfirmed</option></select></label>
    <label>Sales channel<select name="sales_channel"><option value="">All channels</option><option value="online" @selected(request('sales_channel')==='online')>Online</option><option value="walk_in" @selected(request('sales_channel')==='walk_in')>Walk-in</option></select></label>
    <label>Balance<select name="outstanding"><option value="">All invoices</option><option value="1" @selected(request()->boolean('outstanding'))>Outstanding invoices</option></select></label>
    <label>Due date<select name="overdue"><option value="">All due dates</option><option value="1" @selected(request()->boolean('overdue'))>Overdue only</option></select></label>
    <label>Delivery method<select name="delivery_method"><option value="">All delivery methods</option><option value="pickup" @selected(request('delivery_method')==='pickup')>Store pickup</option><option value="lalamove" @selected(request('delivery_method')==='lalamove')>Lalamove</option></select></label>
    <label>Placed from<input name="start_date" type="date" value="{{ request('start_date') }}"></label><label>Placed through<input name="end_date" type="date" value="{{ request('end_date') }}"></label>
    <button class="button small">Filter</button><a class="text-link" href="{{ route('admin.orders') }}">Clear filters</a>
</form>
<div class="panel table-scroll"><table><thead><tr><th>Order</th><th>Customer / recipient</th><th>Quantity / tier</th><th>Total</th><th>Received</th><th>Outstanding</th><th>Payment</th><th>Fulfillment</th><th></th></tr></thead><tbody>
@forelse($orders as $order)
    @php($received = $order->paidAmount())
    @php($balance = $order->outstandingBalance())
    <tr><td>{{ $order->number }}<small>{{ $order->created_at->format('M d, Y') }} · {{ $order->sales_channel === 'walk_in' ? 'Walk-in' : 'Online' }}</small></td>
        <td>{{ $order->user?->name ?? $order->recipient }}@if(!$order->user)<small>Guest invoice</small>@endif</td>
        <td>{{ $order->quantity }} pcs / {{ $order->tier }}</td><td>₱{{ number_format($order->total/100,2) }}</td><td>₱{{ number_format($received/100,2) }}</td>
        <td>₱{{ number_format($balance/100,2) }}
            @if($balance && $order->due_at)
            <small>Due {{ $order->due_at->format('M d, Y · g:i A') }}
                @if($order->status !== 'cancelled' && $order->invoiceOverdue())
                · Overdue
                @endif
            </small>
            @endif
        </td>
        <td><span class="badge">{{ ucfirst(str_replace('_',' ',$order->payment_status)) }}</span></td>
        <td>{{ $order->status === 'cancelled' ? 'Cancelled' : ($order->payment_status === 'paid' ? $order->customerDeliveryLabel() : 'Awaiting full payment') }}</td>
        <td><a class="text-link" href="{{ route('orders.show',$order) }}">Open →</a></td></tr>
@empty<tr><td colspan="9" class="empty-note">No invoices match these filters.</td></tr>@endforelse
</tbody></table></div>{{ $orders->links('pagination') }}
@endsection
