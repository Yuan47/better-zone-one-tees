@extends('layout')
@section('title',$order->number)
@section('content')
<div class="page-heading"><span class="eyebrow">ORDER DETAILS</span><h1>{{ $order->number }}</h1><span class="badge">{{ $order->payment_status==='paid' && $order->status!=='cancelled' ? $order->customerDeliveryLabel() : ucfirst(str_replace('_',' ',$order->status)) }}</span></div>
<div class="checkout-grid"><div class="panel"><h2>Your order</h2><div class="table-scroll"><table><thead><tr><th>Product</th><th>Quantity</th><th>Amount</th></tr></thead><tbody>@foreach($order->items as $item)<tr><td>{{ $item->name }}<small>{{ $item->size }} / {{ $item->color }}</small></td><td>{{ $item->quantity }}</td><td>₱{{ number_format($item->total/100,2) }}</td></tr>@endforeach</tbody></table></div>
<h3>Contact & delivery</h3><p>{{ $order->recipient }} · {{ $order->phone }}</p><p>{{ $order->address }}</p><p>{{ $order->delivery_method==='pickup'?'Store pickup':'Lalamove delivery' }}</p>
@if($order->tracking_url && str_starts_with($order->tracking_url,'https://share.lalamove.com/'))<a class="button" href="{{ $order->tracking_url }}" target="_blank" rel="noopener">Track delivery ↗</a>@endif</div>
<aside class="panel order-summary"><h2>Order summary</h2><dl><div><dt>Clothing</dt><dd>₱{{ number_format($order->merchandise_base/100,2) }}</dd></div>@if($order->discount)<div class="saving"><dt>Bulk discount · 5%</dt><dd>−₱{{ number_format($order->discount/100,2) }}</dd></div>@endif<div><dt>Delivery</dt><dd>₱{{ number_format($order->shipping/100,2) }}</dd></div><div class="total"><dt>Total</dt><dd>₱{{ number_format($order->total/100,2) }}</dd></div></dl>
<div class="pricing-badge">Payment: {{ $order->payment_status }}</div>
@if($order->inventory_released)<p>This unpaid checkout is closed. Its reserved stock has been released.</p><a class="button" href="{{ route('store') }}">Browse products</a>
@elseif($order->payment_status==='pending')<p>Your order is saved. Payment is confirmed only after PayMongo verifies it.</p>@if($order->checkout_url && str_starts_with($order->checkout_url,'https://checkout.paymongo.com/'))<a class="button dark" href="{{ $order->checkout_url }}">Continue payment →</a>@else<p class="muted">The store owner is checking the checkout reference. Please keep this order number when contacting support.</p>@endif
@if($order->checkout_session_id)
<form action="{{ route('orders.payment.check',$order) }}" method="post">@csrf<button class="button">Check payment with PayMongo</button></form>
<details><summary>Close this unpaid order</summary><p>This closes the PayMongo checkout. Stock is released only after the provider verifies that no payment is processing or completed.</p><form action="{{ route('orders.payment.close',$order) }}" method="post">@csrf<button class="button">Close unpaid checkout</button></form></details>
@elseif(auth()->user()->role==='admin')
<details><summary>Recover checkout reference</summary><p>Find this order number in PayMongo. The system checks its reference, amount and mode before attaching it.</p><form class="stack" action="{{ route('admin.orders.payment.recover',$order) }}" method="post">@csrf<label>PayMongo checkout ID<input name="checkout_session_id" required maxlength="123" pattern="cs_[A-Za-z0-9_]+" placeholder="cs_…"></label><button class="button">Verify and recover checkout</button></form></details>
@endif
@else<p>Payment confirmed {{ $order->paid_at?->format('M d, Y · g:i A') }}.</p>@endif
<a class="text-link" href="{{ route('orders.show',$order) }}">Refresh order status ↻</a>
@if(auth()->user()->role!=='customer' && $order->payment_status==='paid')
@if($order->fulfillmentOnHold())<div class="notice">Fulfillment is paused for an approved or processing refund, or a fully refunded payment. Review the refund request before another packing or booking action.</div>@endif
@php($next=in_array($order->status,['to_pack','packing'])?'ready':null)
@if($next && !$order->fulfillmentOnHold())<form action="{{ route('admin.orders.status',$order) }}" method="post">@csrf @method('PATCH')<label class="order-change-reason">Reason for this change<textarea name="reason" required minlength="10" maxlength="1000" rows="2" placeholder="Explain why this order is ready, for example: all pieces packed and checked.">{{ old('reason') }}</textarea></label><button class="button dark" name="status" value="{{ $next }}">Mark Ready for delivery</button></form>@endif
@if($order->status==='ready' && $order->delivery_method==='lalamove' && !$order->delivery_id && !$order->fulfillmentOnHold())<form action="{{ route('admin.orders.delivery',$order) }}" method="post">@csrf<button class="button dark">Book Lalamove {{ config('shop.lalamove.mode')==='sandbox'?'sandbox':'' }} delivery</button></form>@endif
@if($order->delivery_id && in_array($order->status,['out_for_delivery','delivery_attention','delivery_booking','completed']))<form action="{{ route('admin.orders.delivery.refresh',$order) }}" method="post">@csrf<button class="button">Check delivery with Lalamove</button></form>@endif
@if($order->status==='delivery_booking' && !$order->delivery_id)<div class="notice">The delivery booking response is uncertain. Check the original booking in Lalamove before requesting another courier.</div><form class="stack" action="{{ route('admin.orders.delivery.recover',$order) }}" method="post">@csrf<label>Lalamove delivery ID<input name="delivery_id" required maxlength="100" pattern="[A-Za-z0-9_-]+"></label><button class="button">Verify and recover delivery</button></form>@endif
@endif</aside></div>
@include('partials.order-receipt')
@if($order->payment_status==='paid' && $order->status==='completed' && $order->customer_confirmed_at)
<section class="panel refund-order"><h2>Refunds & returns</h2>
@forelse($order->refunds->sortByDesc('id') as $refund)<article><h3>Request #{{ $refund->id }} · {{ $refund->label() }}</h3><p>Requested ₱{{ number_format($refund->requested_amount/100,2) }} on {{ $refund->created_at->format('M d, Y') }}.</p><p class="preserve-lines">Your request: {{ $refund->reason }}</p>@if($refund->decision_note)<p class="preserve-lines">Store response: {{ $refund->decision_note }}</p>@endif @if($refund->status==='succeeded')<p>PayMongo confirmed ₱{{ number_format($refund->amount/100,2) }} on {{ $refund->confirmed_at?->format('M d, Y') }}. Your payment provider determines when it appears in your account.</p>@endif @if(auth()->user()->role!=='customer')<a class="text-link" href="{{ route('admin.refunds.show',$refund) }}">Review refund →</a>@endif</article>@empty<p class="muted">No refund request for this order.</p>@endforelse
@php($remainingRefund=$order->total-$order->refunds->where('status','succeeded')->sum('amount'))
@if(auth()->user()->id===$order->user_id && auth()->user()->role==='customer' && $remainingRefund>=100 && !$order->refunds->whereIn('status',['requested','approved','pending','processing'])->count())
<details><summary>Request a refund or return</summary><p>The store owner reviews eligibility and the amount. A request does not yet confirm a refund.</p><form class="stack" action="{{ route('orders.refunds.store',$order) }}" method="post">@csrf<label>Reason<textarea name="reason" required minlength="10" maxlength="2000" rows="3" placeholder="Tell us what happened with your order."></textarea></label><button class="button">Send request for review</button></form></details>
@endif
</section>
@endif
@endsection
