@extends('layout')
@section('title','Refund request #'.$refund->id)
@section('crumb','Refunds')
@section('content')
@php($cashRefund = $refund->isCash())
@php($source = $refund->paymentAttempt)
@php($cashOptions = $paymentOptions->filter(fn($option) => $option['payment']->method === 'cash'))
@php($providerOptions = $paymentOptions->filter(fn($option) => $option['payment']->method === 'paymongo'))
<div class="page-heading"><span class="eyebrow">REFUND REQUEST #{{ $refund->id }}</span><h1>{{ $refund->order->number }}</h1><span class="badge">{{ $refund->label() }}</span></div>
<div class="checkout-grid"><section class="panel"><h2>Request details</h2><p>{{ $refund->order->user?->name ?? $refund->order->recipient ?? 'Walk-in customer' }} · requested {{ $refund->created_at->format('M d, Y · g:i A') }}</p><h3>Customer’s reason</h3><p class="preserve-lines">{{ $refund->reason }}</p><p>Requested: <b>₱{{ number_format($refund->requested_amount/100,2) }}</b></p>
@if($refund->decision_note)<h3>Store decision</h3><p class="preserve-lines">{{ $refund->decision_note }}</p><p>Approved amount: {{ $refund->approved_amount===null?'—':'₱'.number_format($refund->approved_amount/100,2) }}</p>@endif
@if($source)<h3>Payment receipt</h3><p>{{ $source->method==='cash'?'Cash':'PayMongo' }} receipt #{{ $source->id }} · received ₱{{ number_format($source->amount/100,2) }}</p>@endif
@if($refund->confirmed_at)<p>{{ $cashRefund?'Cash return recorded':'PayMongo confirmation' }}: {{ $refund->confirmed_at->format('M d, Y · g:i A') }}</p>@endif
@if($refund->amount!==null)<p>{{ $cashRefund?'Recorded cash amount':'Verified provider amount' }}: <b>₱{{ number_format($refund->amount/100,2) }}</b></p>@endif
@if($refund->cash_reason)<h3>Cash return record</h3><p class="preserve-lines">{{ $refund->cash_reason }}</p><p>Recorded by {{ $refund->cashRecordedBy?->name ?? 'the store owner' }}.</p>@endif
<p class="muted">A refund does not automatically return clothing to inventory or cancel a courier. Check the returned items and delivery before changing physical stock.</p>
<a class="button" href="{{ route('orders.show',$refund->order) }}">Open order</a><a class="text-link" href="{{ route('admin.refunds.index') }}">← All refund requests</a></section>
<aside class="panel stack"><h2>Store actions</h2>@if(!$refund->order->deliveryConfirmed())<p>Online refund requests can be processed after the customer confirms Delivered and submits their review. Fully paid walk-in orders use the store’s recorded receipt.</p>@endif
@if($refund->status==='requested' && auth()->user()->role==='admin' && $refund->order->deliveryConfirmed())
<form class="stack" method="post" action="{{ route('admin.refunds.review',$refund) }}">@csrf @method('PATCH')<label>Decision<select name="decision"><option value="approved">Approve for processing</option><option value="rejected">Decline request</option></select></label>
@if($paymentOptions->isNotEmpty())<label>Payment receipt for this refund<select name="payment_attempt_id"><option value="">Choose a receipt when approving</option>@foreach($paymentOptions as $option)<option value="{{ $option['payment']->id }}" @selected((string)old('payment_attempt_id',$refund->payment_attempt_id)===(string)$option['payment']->id)>{{ $option['payment']->method==='cash'?'Cash':'PayMongo' }} #{{ $option['payment']->id }} · available ₱{{ number_format($option['remaining']/100,2) }}</option>@endforeach</select></label><p class="muted">Approve each refund against one receipt. The amount cannot exceed that receipt or the remaining invoice payment.</p>@endif
<label>Approved amount (PHP)<input name="approved_amount" type="number" step=".01" min="1" max="{{ $refund->requested_amount/100 }}" value="{{ old('approved_amount',number_format($refund->requested_amount/100,2,'.','')) }}"></label><label>Reason shown to customer<textarea name="decision_note" required minlength="5" maxlength="2000" rows="4">{{ old('decision_note') }}</textarea></label><button class="button dark">Save decision</button></form>
@elseif($refund->status==='requested')<p>The store owner reviews refund decisions. You can monitor this request and its order.</p>@endif
@if($refund->status==='approved' && !$refund->provider_refund_id && auth()->user()->role==='admin' && $refund->order->deliveryConfirmed())
@if((!$source || $source->method==='paymongo') && ($source || $providerOptions->isNotEmpty() || $paymentOptions->isEmpty()))
<p>Complete the approved refund in your PayMongo dashboard against the selected receipt. Enter its refund reference here to verify the payment, amount and status.</p><form class="stack" method="post" action="{{ route('admin.refunds.recover',$refund) }}">@csrf
@if($source)<input type="hidden" name="payment_attempt_id" value="{{ $source->id }}">@elseif($providerOptions->isNotEmpty())<label>PayMongo payment receipt<select name="payment_attempt_id" required><option value="">Choose the refunded receipt</option>@foreach($providerOptions as $option)<option value="{{ $option['payment']->id }}">PayMongo #{{ $option['payment']->id }} · available ₱{{ number_format($option['remaining']/100,2) }}</option>@endforeach</select></label>@endif
<label>PayMongo refund ID<input name="provider_refund_id" required maxlength="124" pattern="ref_[A-Za-z0-9_]+" placeholder="ref_…"></label><button class="button dark">Verify refund reference</button></form>
@endif
@if((!$source && $cashOptions->isNotEmpty()) || $cashRefund)
<h3>Record returned cash</h3><p>Only record this after the approved cash amount has actually been handed back.</p><form class="stack" method="post" action="{{ route('admin.refunds.cash',$refund) }}">@csrf
@if($source)<input type="hidden" name="payment_attempt_id" value="{{ $source->id }}">@else<label>Cash payment receipt<select name="payment_attempt_id" required><option value="">Choose the cash receipt</option>@foreach($cashOptions as $option)<option value="{{ $option['payment']->id }}">Cash #{{ $option['payment']->id }} · available ₱{{ number_format($option['remaining']/100,2) }}</option>@endforeach</select></label>@endif
<p>Cash to return: <b>₱{{ number_format($refund->approved_amount/100,2) }}</b></p><label>Cash return reason<textarea name="cash_reason" required minlength="10" maxlength="2000" rows="4">{{ old('cash_reason') }}</textarea></label><button class="button dark">Record completed cash return</button></form>
@endif
@endif
@if($refund->provider_refund_id && !$cashRefund)<p>Provider reference: {{ $refund->provider_refund_id }}</p><form method="post" action="{{ route('admin.refunds.check',$refund) }}">@csrf<button class="button">Check refund with PayMongo</button></form>@endif
<p class="muted">Saving a store decision does not move money. PayMongo verification reads an existing refund. A cash return record does not transfer money.</p>
</aside></div>
@endsection
