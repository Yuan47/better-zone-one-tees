@if($order->payment_status==='paid' && $order->status!=='cancelled' && $order->sales_channel!=='walk_in')
<section class="panel receipt-panel"><h2>Confirm your delivery</h2>
@if($order->customer_confirmed_at && $order->review)
<p>Delivered confirmed by the customer on {{ $order->customer_confirmed_at->format('M d, Y · g:i A') }}.</p>
<h3>Customer rating · {{ $order->review->rating }} / 5</h3>
@if($order->review->comment)<p class="preserve-lines">{{ $order->review->comment }}</p>@else<p class="muted">No written review added.</p>@endif
@elseif(auth()->user()->role==='customer' && auth()->id()===$order->user_id)
@php($canReceive=$order->delivery_method==='pickup' ? $order->status==='ready' : ($order->status==='out_for_delivery' && $order->provider_completed_at))
@if($canReceive)
<p>Received your clothing? Choose a star rating and confirm receipt. A written review is optional. Refund requests become available after this step.</p>
<form class="stack" action="{{ route('orders.receipt',$order) }}" method="post">@csrf
<fieldset class="receipt-rating" aria-describedby="receipt-rating-help">
<legend>Your rating <span class="muted">(required)</span></legend>
<div class="receipt-stars">
@foreach([1=>'Poor',2=>'Fair',3=>'Good',4=>'Very good',5=>'Excellent'] as $rating=>$description)
<label class="receipt-star" title="{{ $rating }} {{ $rating===1?'star':'stars' }} · {{ $description }}">
<input class="sr-only" type="radio" name="rating" value="{{ $rating }}" required @checked((string)old('rating')===(string)$rating)>
<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="m12 2.5 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3.1-5.8 3.1 1.1-6.5L2.6 9.3l6.5-.9Z"/></svg>
<span class="sr-only">{{ $rating }} {{ $rating===1?'star':'stars' }} · {{ $description }}</span>
</label>
@endforeach
</div>
<p class="muted" id="receipt-rating-help">Choose 1 star for poor to 5 stars for excellent.</p>
</fieldset>
<label>Your review <span class="muted">Optional</span><textarea name="comment" maxlength="2000" rows="4" placeholder="Tell us about the clothing you received (optional).">{{ old('comment') }}</textarea></label>
<label class="receipt-check receipt-confirmation"><input type="checkbox" name="received" value="1" required @checked(old('received'))><span><strong>I have received my order.</strong><small>Confirm only after you have received and checked your clothing.</small></span></label>
<button class="button dark">Confirm Delivered</button></form>
@else<p class="muted">You can confirm Delivered and choose your rating here once the courier confirms arrival, or your pickup order is ready. A written review is optional.</p>@endif
@else<p class="muted">Only the customer can confirm receipt and choose their rating. A written review is optional.</p>@endif
</section>
@endif
