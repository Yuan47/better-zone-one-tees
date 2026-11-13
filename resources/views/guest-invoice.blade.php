@extends('layout')
@section('title',$order->number)
@section('content')
@if(str_starts_with($order->number,'ZOT-DEMO-'))<div class="notice">SIMULATED / TEST ORDER · For demonstration. No real money or physical delivery is represented by this record.</div>@endif
<div class="page-heading"><span class="eyebrow">PRIVATE WALK-IN INVOICE</span><h1>{{ $order->number }}</h1><p>For {{ $order->recipient }} · {{ $order->inventory_released || $order->status==='cancelled'?'Closed invoice · no payment due':($order->deliveryConfirmed()?'Delivered · payment received':'Awaiting payment received in full') }}</p><p class="muted">Keep this private link safe. Anyone with the link can view this invoice and its payment history. No account is required.</p></div>
@if($order->payments->contains('method', 'paymongo') && $order->status !== 'cancelled' && !$order->inventory_released)
@include('partials.checkout-progress', ['checkoutStage' => $order->payment_status === 'paid' ? 4 : 3])
@endif
<div class="panel table-scroll"><table><thead><tr><th>Clothing</th><th>Quantity</th><th>Amount</th></tr></thead><tbody>@foreach($order->items as $item)<tr><td>{{ $item->name }}<small>{{ $item->size }} / {{ $item->color }}</small></td><td>{{ $item->quantity }}</td><td>₱{{ number_format($item->total/100,2) }}</td></tr>@endforeach</tbody></table></div>
@include('partials.invoice-payments',['guest'=>true])
@if($order->deliveryConfirmed())<section class="panel"><h2>Refunds & returns</h2>
@include('partials.refund-summary')
@include('partials.return-policy')
@foreach($order->refunds->sortByDesc('id') as $refund)<article><h3>Request #{{ $refund->id }} · {{ $refund->label() }}</h3><p>Requested ₱{{ number_format($refund->requested_amount/100,2) }} on {{ $refund->created_at->format('M d, Y') }}.</p>
@include('partials.return-request-policy')
@if($refund->decision_note)<p class="preserve-lines">Store response: {{ $refund->decision_note }}</p>@endif @if($refund->approved_amount!==null)<p>Approved for processing: ₱{{ number_format($refund->approved_amount/100,2) }}.</p>@endif @if($refund->status==='succeeded')<p>{{ $refund->isCash()?'Cash refund recorded':'PayMongo confirmed' }} ₱{{ number_format($refund->amount/100,2) }} on {{ $refund->confirmed_at?->format('M d, Y · g:i A') }}.</p>@endif</article>@endforeach
@if(\App\Models\Refund::remainingForOrder($order)>=100 && !$order->refunds->whereIn('status',['requested','approved','pending','processing'])->count())<form class="stack" method="post" enctype="multipart/form-data" action="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('guest.orders.refunds',\Carbon\Carbon::createFromTimestamp((int)request('expires')),['order'=>$order->id]) }}">@csrf<label>Reason for your refund request<textarea name="reason" required minlength="10" maxlength="2000" rows="3">{{ \App\Support\FormInput::old('reason') }}</textarea></label><label>Required photo proof (JPG, PNG or WebP; up to 3 MB)<input type="file" name="customer_photo" accept="image/jpeg,image/png,image/webp" required aria-describedby="refund-photo-help"></label><p class="muted" id="refund-photo-help">Upload a clear photo showing the clothing and the issue. Only authorized store staff and the owner can open the photo. Select it again if the form reports an error.</p><button class="button">Send request for store review</button></form>@endif</section>@endif
@endsection
