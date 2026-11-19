@if($order->payment_status==='paid' && $order->status!=='cancelled' && !$order->inventory_released && $order->sales_channel!=='walk_in')
<section class="panel receipt-panel" id="order-receipt" aria-labelledby="order-receipt-heading"><h2 id="order-receipt-heading">Order received</h2>
@if($order->deliveryConfirmed())
<p class="receipt-success"><strong>Order received · Delivered</strong><br>Confirmed by the customer on {{ $order->customer_confirmed_at?->format('M d, Y · g:i A') }}.</p>
@if($order->review)
<h3>Customer rating · {{ $order->review->rating }} / 5</h3>
@if($order->review->comment)<p class="preserve-lines">{{ $order->review->comment }}</p>@else<p class="muted">No written review added.</p>@endif
@else<p class="muted">No rating was recorded for this earlier order.</p>@endif
@if(auth()->user()->role==='customer' && auth()->id()===$order->user_id)<a class="button" href="#refunds-returns">Return / refund instructions →</a>@endif
@elseif(auth()->user()->role==='customer' && auth()->id()===$order->user_id)
@php($canReceive=$order->payment_id && ($order->delivery_method==='pickup' ? $order->status==='ready' : ($order->delivery_method==='lalamove' && $order->status==='out_for_delivery' && $order->provider_completed_at)))
@if($canReceive)
<p>Confirm only after you have received and checked your clothing.</p><ol class="receipt-instructions"><li>Choose a rating from 1 to 5 stars.</li><li>Add a written review if you want to; it is optional.</li><li>Tick <strong>I have received my order</strong>, then choose <strong>Confirm Delivered</strong>.</li></ol><p class="muted">Your order will show Delivered in Completed purchases. Refund requests become available after this step.</p>
<form class="stack" action="{{ route('orders.receipt',$order) }}" method="post">@csrf
<fieldset class="receipt-rating" aria-describedby="receipt-rating-help">
<legend>Your rating <span class="muted">(required)</span></legend>
<div class="receipt-stars">
@foreach([1=>'Poor',2=>'Fair',3=>'Good',4=>'Very good',5=>'Excellent'] as $rating=>$description)
<label class="receipt-star" title="{{ $rating }} {{ $rating===1?'star':'stars' }} · {{ $description }}">
<input class="sr-only" type="radio" name="rating" value="{{ $rating }}" required @checked((string)\App\Support\FormInput::old('rating')===(string)$rating)>
<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="m12 2.5 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3.1-5.8 3.1 1.1-6.5L2.6 9.3l6.5-.9Z"/></svg>
<span class="sr-only">{{ $rating }} {{ $rating===1?'star':'stars' }} · {{ $description }}</span>
</label>
@endforeach
</div>
<p class="muted" id="receipt-rating-help">Choose 1 star for poor to 5 stars for excellent.</p>
</fieldset>
<label>Your review <span class="muted">Optional</span><textarea name="comment" maxlength="2000" rows="4" placeholder="Tell us about the clothing you received (optional).">{{ \App\Support\FormInput::old('comment') }}</textarea></label>
<small class="muted">Your stars and optional review will appear on the purchased products with your name masked. Please leave out addresses, contact details and payment information.</small>
<label class="receipt-check receipt-confirmation"><input type="checkbox" name="received" value="1" required @checked(\App\Support\FormInput::old('received'))><span><strong>I have received my order.</strong><small>Confirm only after you have received and checked your clothing.</small></span></label>
<button class="button dark">Confirm Delivered</button></form>
@else<p class="muted">Receipt confirmation is not available yet. Your order must be fully paid and its courier completion verified, or its store pickup marked ready. Check the delivery status above; if you already have the clothing and this still appears, ask store staff to check the original order.</p><p class="muted">When confirmation opens here, choose 1–5 stars, optionally add a written review, tick I have received my order, and choose Confirm Delivered. Return / refund instructions will then appear on this order.</p>@endif
@else<p class="muted">Only the customer can confirm receipt and choose their rating. A written review is optional.</p>@endif
</section>
@endif
