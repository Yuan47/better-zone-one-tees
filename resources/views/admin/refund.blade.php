@extends('layout')
@section('title','Refund request #'.$refund->id)
@section('crumb','Refunds')
@section('content')
<div class="page-heading"><span class="eyebrow">REFUND REQUEST #{{ $refund->id }}</span><h1>{{ $refund->order->number }}</h1><span class="badge">{{ $refund->label() }}</span></div>
<div class="checkout-grid"><section class="panel"><h2>Request details</h2><p>{{ $refund->order->user->name }} · requested {{ $refund->created_at->format('M d, Y · g:i A') }}</p><h3>Customer’s reason</h3><p class="preserve-lines">{{ $refund->reason }}</p><p>Requested: <b>₱{{ number_format($refund->requested_amount/100,2) }}</b></p>
@if($refund->decision_note)<h3>Store decision</h3><p class="preserve-lines">{{ $refund->decision_note }}</p><p>Approved amount: {{ $refund->approved_amount===null?'—':'₱'.number_format($refund->approved_amount/100,2) }}</p>@endif
@if($refund->confirmed_at)<p>PayMongo confirmation: {{ $refund->confirmed_at->format('M d, Y · g:i A') }}</p>@endif
@if($refund->amount!==null)<p>Verified provider amount: <b>₱{{ number_format($refund->amount/100,2) }}</b></p>@endif
<p class="muted">A refund does not automatically return clothing to inventory or cancel a courier. Check the returned items and delivery before changing physical stock.</p>
<a class="button" href="{{ route('orders.show',$refund->order) }}">Open order</a><a class="text-link" href="{{ route('admin.refunds.index') }}">← All refund requests</a></section>
<aside class="panel stack"><h2>Store actions</h2>
@if($refund->status==='requested' && auth()->user()->role==='admin')
<form class="stack" method="post" action="{{ route('admin.refunds.review',$refund) }}">@csrf @method('PATCH')<label>Decision<select name="decision"><option value="approved">Approve for processing</option><option value="rejected">Decline request</option></select></label><label>Approved amount (PHP)<input name="approved_amount" type="number" step=".01" min="1" max="{{ $refund->requested_amount/100 }}" value="{{ old('approved_amount',number_format($refund->requested_amount/100,2,'.','')) }}"></label><label>Reason shown to customer<textarea name="decision_note" required minlength="5" maxlength="2000" rows="4">{{ old('decision_note') }}</textarea></label><button class="button dark">Save decision</button></form>
@elseif($refund->status==='requested')<p>The store owner reviews refund decisions. You can monitor this request and its order.</p>@endif
@if($refund->status==='approved' && !$refund->provider_refund_id && auth()->user()->role==='admin')
<p>Complete the approved refund in your PayMongo dashboard. Enter its refund reference here to verify the payment, amount and status.</p><form class="stack" method="post" action="{{ route('admin.refunds.recover',$refund) }}">@csrf<label>PayMongo refund ID<input name="provider_refund_id" required maxlength="124" pattern="ref_[A-Za-z0-9_]+" placeholder="ref_…"></label><button class="button dark">Verify refund reference</button></form>
@endif
@if($refund->provider_refund_id)<p>Provider reference: {{ $refund->provider_refund_id }}</p><form method="post" action="{{ route('admin.refunds.check',$refund) }}">@csrf<button class="button">Check refund with PayMongo</button></form>@endif
<p class="muted">Saving a store decision does not move money. Verification reads the existing refund from PayMongo.</p>
</aside></div>
@endsection
