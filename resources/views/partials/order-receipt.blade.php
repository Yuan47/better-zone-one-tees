@if($order->payment_status==='paid' && $order->status!=='cancelled')
<section class="panel receipt-panel"><h2>Confirm your delivery</h2>
@if($order->customer_confirmed_at && $order->review)
<p>Delivered confirmed by the customer on {{ $order->customer_confirmed_at->format('M d, Y · g:i A') }}.</p>
<h3>Customer review · {{ $order->review->rating }} / 5</h3><p class="preserve-lines">{{ $order->review->comment }}</p>
@elseif(auth()->user()->role==='customer' && auth()->id()===$order->user_id)
@php($canReceive=$order->delivery_method==='pickup' ? $order->status==='ready' : ($order->status==='out_for_delivery' && $order->provider_completed_at))
@if($canReceive)
<p>Received your clothing? Add your review and confirm Delivered. Refund requests become available after this step.</p>
<form class="stack" action="{{ route('orders.receipt',$order) }}" method="post">@csrf
<label>Your rating<select name="rating" required><option value="">Choose a rating</option>@foreach([5,4,3,2,1] as $rating)<option value="{{ $rating }}" @selected((string)old('rating')===(string)$rating)>{{ $rating }} / 5</option>@endforeach</select></label>
<label>Your review<textarea name="comment" required minlength="10" maxlength="2000" rows="4" placeholder="Tell us about the clothing you received.">{{ old('comment') }}</textarea></label>
<label class="receipt-check"><input type="checkbox" name="received" value="1" required>I confirm I have received this order.</label>
<button class="button dark">Confirm Delivered & submit review</button></form>
@else<p class="muted">You can confirm Delivered and leave your review here once the courier confirms arrival, or your pickup order is ready.</p>@endif
@else<p class="muted">Only the customer can confirm Delivered and submit the required review.</p>@endif
</section>
@endif
