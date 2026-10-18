@extends('layout')
@section('title',$order->number)
@section('content')
<div class="page-heading"><span class="eyebrow">ORDER DETAILS</span><h1>{{ $order->number }}</h1><span class="badge">{{ $order->payment_status==='paid' && $order->status!=='cancelled' ? $order->customerDeliveryLabel() : ucfirst(str_replace('_',' ',$order->status)) }}</span></div>
<div class="checkout-grid"><div class="panel"><h2>Your order</h2><div class="table-scroll"><table><thead><tr><th>Product</th><th>Quantity</th><th>Amount</th></tr></thead><tbody>@foreach($order->items as $item)<tr><td>{{ $item->name }}<small>{{ $item->size }} / {{ $item->color }}</small></td><td>{{ $item->quantity }}</td><td>₱{{ number_format($item->total/100,2) }}</td></tr>@endforeach</tbody></table></div>
<h3>Contact & delivery</h3><p>{{ $order->recipient }} · {{ $order->phone }}</p><p>{{ $order->address }}</p><p>{{ $order->delivery_method==='pickup'?'Store pickup':'Lalamove delivery' }}</p>
@if($order->tracking_url && str_starts_with($order->tracking_url,'https://share.lalamove.com/'))<a class="button" href="{{ $order->tracking_url }}" target="_blank" rel="noopener">Track delivery ↗</a>@endif</div>
<aside class="panel order-summary"><h2>Order summary</h2><dl><div><dt>Clothing</dt><dd>₱{{ number_format($order->merchandise_base/100,2) }}</dd></div>@if($order->discount)<div class="saving"><dt>Bulk discount · 5%</dt><dd>−₱{{ number_format($order->discount/100,2) }}</dd></div>@endif<div><dt>Delivery</dt><dd>₱{{ number_format($order->shipping/100,2) }}</dd></div><div class="total"><dt>Total</dt><dd>₱{{ number_format($order->total/100,2) }}</dd></div></dl>
<div class="pricing-badge">Payment: {{ str_replace('_',' ',$order->payment_status) }}</div>
@if($order->inventory_released)<p>This unpaid invoice is closed. Reserved stock has been released.</p>@elseif($order->payment_status==='paid')<p>Payment received in full {{ $order->paid_at?->format('M d, Y · g:i A') }}.</p>@else<p>The balance remains on this original invoice. See payments below.</p>@endif
<a class="text-link" href="{{ route('orders.show',$order) }}">Refresh order status ↻</a>
@if(auth()->user()->role!=='customer' && $order->payment_status==='paid')
@if($order->fulfillmentOnHold())<div class="notice">Fulfillment is paused for an approved or processing refund, or a fully refunded payment. Review the refund request before another packing or booking action.</div>@endif
@php($next=in_array($order->status,['to_pack','packing'])?'ready':null)
@if($next && !$order->fulfillmentOnHold())<form action="{{ route('admin.orders.status',$order) }}" method="post">@csrf @method('PATCH')<label class="order-change-reason">Reason for this change<textarea name="reason" required minlength="10" maxlength="1000" rows="2" placeholder="Explain why this order is ready, for example: all pieces packed and checked.">{{ old('reason') }}</textarea></label><button class="button dark" name="status" value="{{ $next }}">Mark Ready for delivery</button></form>@endif
@if($order->status==='ready' && $order->delivery_method==='lalamove' && !$order->delivery_id && !$order->fulfillmentOnHold())<form action="{{ route('admin.orders.delivery',$order) }}" method="post">@csrf<button class="button dark">Book Lalamove {{ config('shop.lalamove.mode')==='sandbox'?'sandbox':'' }} delivery</button></form>@endif
@if($order->delivery_id && in_array($order->status,['out_for_delivery','delivery_attention','delivery_booking','completed']))<form action="{{ route('admin.orders.delivery.refresh',$order) }}" method="post">@csrf<button class="button">Check delivery with Lalamove</button></form>@endif
@if($order->status==='delivery_booking' && !$order->delivery_id)<div class="notice">The delivery booking response is uncertain. Check the original booking in Lalamove before requesting another courier.</div><form class="stack" action="{{ route('admin.orders.delivery.recover',$order) }}" method="post">@csrf<label>Lalamove delivery ID<input name="delivery_id" required maxlength="100" pattern="[A-Za-z0-9_-]+"></label><button class="button">Verify and recover delivery</button></form>@endif
@endif</aside></div>
@include('partials.invoice-payments')
@include('admin.partials.invoice-reminders')
@if(!$order->user_id && auth()->user()->role!=='customer')
<section class="panel"><h2>Private customer invoice</h2><p>Share this link only with this walk-in customer. It allows viewing this invoice, paying its balance and requesting an eligible refund without an account.</p><a class="button" href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('guest.orders.show',now()->addDays(30),['order'=>$order->id]) }}">Open private customer invoice →</a><small>The link expires in 30 days. This staff page can create another link when needed.</small></section>
@endif
@if($order->sales_channel==='walk_in' && $order->deliveryConfirmed())<section class="panel"><h2>Walk-in · Payment received</h2><p>Full payment was received on {{ $order->walk_in_received_at->format('M d, Y · g:i A') }}. This walk-in sale is Delivered. The walk-in sale is complete.</p></section>@endif
@include('partials.order-receipt')
@if($order->payment_status==='paid' && $order->deliveryConfirmed())
<section class="panel refund-order"><h2>Refunds & returns</h2>
@include('partials.return-policy')
@include('partials.refund-summary')
@forelse($order->refunds->sortByDesc('id') as $refund)<article><h3>Request #{{ $refund->id }} · {{ $refund->label() }}</h3><p>Requested ₱{{ number_format($refund->requested_amount/100,2) }} on {{ $refund->created_at->format('M d, Y') }}.</p>
@include('partials.return-request-policy')
<p class="preserve-lines">Your request: {{ $refund->reason }}</p>@if($refund->decision_note)<p class="preserve-lines">Store response: {{ $refund->decision_note }}</p>@endif @if($refund->approved_amount!==null)<p>Approved for processing: ₱{{ number_format($refund->approved_amount/100,2) }}.</p>@endif @if($refund->status==='succeeded')<p>{{ $refund->isCash()?'Cash refund recorded':'PayMongo confirmed' }} ₱{{ number_format($refund->amount/100,2) }} on {{ $refund->confirmed_at?->format('M d, Y') }}.</p>@endif @if(auth()->user()->role!=='customer')<a class="text-link" href="{{ route('admin.refunds.show',$refund) }}">Review refund →</a>@endif</article>@empty<p class="muted">No refund request for this order.</p>@endforelse
@php($remainingRefund=\App\Models\Refund::remainingForOrder($order))
@if(auth()->user()->id===$order->user_id && auth()->user()->role==='customer' && $remainingRefund>=100 && !$order->refunds->whereIn('status',['requested','approved','pending','processing'])->count())
<details><summary>Request a refund or return</summary><p>The store owner reviews eligibility and the amount after inspecting the returned clothing. A request does not yet confirm a refund.</p><form class="stack" action="{{ route('orders.refunds.store',$order) }}" method="post" enctype="multipart/form-data">@csrf<label>Reason<textarea name="reason" required minlength="10" maxlength="2000" rows="3" placeholder="Tell us what happened with your order."></textarea></label><label>Optional supporting photo (JPG, PNG or WebP; up to 3 MB)<input type="file" name="customer_photo" accept="image/jpeg,image/png,image/webp"></label><p class="muted">Photos are private and accessible only to authorized store staff and the owner.</p><button class="button">Send request for review</button></form></details>
@endif
</section>
@endif
@endsection
