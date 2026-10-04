@extends('layout')
@section('title',$order->number)
@section('content')
<div class="page-heading"><span class="eyebrow">ORDER DETAILS</span><h1>{{ $order->number }}</h1><span class="badge">{{ str_replace('_',' ',$order->status) }}</span></div>
<div class="checkout-grid"><div class="panel"><h2>Your order</h2><div class="table-scroll"><table><thead><tr><th>Product</th><th>Quantity</th><th>Amount</th></tr></thead><tbody>@foreach($order->items as $item)<tr><td>{{ $item->name }}<small>{{ $item->size }} / {{ $item->color }}</small></td><td>{{ $item->quantity }}</td><td>₱{{ number_format($item->total/100,2) }}</td></tr>@endforeach</tbody></table></div>
<h3>Contact & delivery</h3><p>{{ $order->recipient }} · {{ $order->phone }}</p><p>{{ $order->address }}</p><p>{{ $order->delivery_method==='pickup'?'Store pickup':'Lalamove delivery' }}</p>
@if($order->tracking_url && str_starts_with($order->tracking_url,'https://share.lalamove.com/'))<a class="button" href="{{ $order->tracking_url }}" target="_blank" rel="noopener">Track delivery ↗</a>@endif</div>
<aside class="panel order-summary"><h2>Order summary</h2><dl><div><dt>Clothing</dt><dd>₱{{ number_format($order->merchandise_base/100,2) }}</dd></div>@if($order->discount)<div class="saving"><dt>Bulk discount · 5%</dt><dd>−₱{{ number_format($order->discount/100,2) }}</dd></div>@endif<div><dt>Delivery</dt><dd>₱{{ number_format($order->shipping/100,2) }}</dd></div><div class="total"><dt>Total</dt><dd>₱{{ number_format($order->total/100,2) }}</dd></div></dl>
<div class="pricing-badge">Payment: {{ $order->payment_status }}</div>
@if($order->payment_status==='pending')<p>Payment is confirmed after the store receives and verifies PayMongo’s notification. Returning from checkout does not confirm payment.</p>@if($order->checkout_url && str_starts_with($order->checkout_url,'https://checkout.paymongo.com/'))<a class="button dark" href="{{ $order->checkout_url }}">Continue payment →</a>@else<p class="muted">Checkout needs staff reconciliation.</p>@endif
@else<p>Payment confirmed {{ $order->paid_at?->format('M d, Y · g:i A') }}.</p>@endif
<a class="text-link" href="{{ route('orders.show',$order) }}">Refresh order status ↻</a>
@if(auth()->user()->role!=='customer' && $order->payment_status==='paid')
@php($next=['to_pack'=>'packing','packing'=>'ready','ready'=>$order->delivery_method==='pickup'?'completed':null][$order->status]??null)
@if($next)<form action="{{ route('admin.orders.status',$order) }}" method="post">@csrf @method('PATCH')<button class="button dark" name="status" value="{{ $next }}">Mark {{ $next }}</button></form>@endif
@if($order->status==='ready' && $order->delivery_method==='lalamove' && !$order->delivery_id)<form action="{{ route('admin.orders.delivery',$order) }}" method="post">@csrf<button class="button dark">Book Lalamove {{ config('shop.lalamove.mode')==='sandbox'?'sandbox':'' }} delivery</button></form>@endif
@endif</aside></div>
@endsection