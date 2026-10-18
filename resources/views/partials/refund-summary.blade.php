@php
    $confirmedRefundTotal = (int) $order->refunds->where('status','succeeded')->sum('amount');
    $processingRefundTotal = (int) $order->refunds->whereIn('status',['pending','processing'])->whereNotNull('provider_refund_id')->sum('amount');
    $refundReviewBalance = \App\Models\Refund::remainingForOrder($order);
@endphp
<div class="invoice-totals" aria-label="Refund balance">
    <div><span>Payments received</span><strong>₱{{ number_format(\App\Models\Refund::receivedForOrder($order)/100,2) }}</strong></div>
    <div><span>Confirmed refunds</span><strong>₱{{ number_format($confirmedRefundTotal/100,2) }}</strong></div>
    <div><span>Remaining paid amount for review</span><strong>₱{{ number_format($refundReviewBalance/100,2) }}</strong></div>
</div>
@if($processingRefundTotal>0)
<p class="mini-note">₱{{ number_format($processingRefundTotal/100,2) }} is being processed by PayMongo. This amount is held aside from the remaining paid amount until its result is confirmed.</p>
@endif
<p class="muted">A request or approval does not confirm that money has been returned. Online refunds require PayMongo verification; cash returns are recorded by the owner after handing back the money.</p>
@if($order->payment_plan==='partial' || $order->payments->where('status','paid')->count()>1)
<p class="mini-note">Installment refunds are processed against each payment receipt. After the current request is completed, you can request a review of any remaining paid amount. The store owner reviews each request.</p>
@endif
