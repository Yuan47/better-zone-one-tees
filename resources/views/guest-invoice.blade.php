@extends('layout')
@section('title',$order->number)
@section('content')
<div class="page-heading"><span class="eyebrow">PRIVATE WALK-IN INVOICE</span><h1>{{ $order->number }}</h1><p>For {{ $order->recipient }} · {{ $order->deliveryConfirmed()?'Delivered · payment received':'Awaiting payment received in full' }}</p><p class="muted">Keep this private link safe. Anyone with the link can view this invoice and its payment history. No account is required.</p></div>
<div class="panel table-scroll"><table><thead><tr><th>Clothing</th><th>Quantity</th><th>Amount</th></tr></thead><tbody>@foreach($order->items as $item)<tr><td>{{ $item->name }}<small>{{ $item->size }} / {{ $item->color }}</small></td><td>{{ $item->quantity }}</td><td>₱{{ number_format($item->total/100,2) }}</td></tr>@endforeach</tbody></table></div>
@include('partials.invoice-payments',['guest'=>true])
@if($order->deliveryConfirmed())<section class="panel"><h2>Refunds & returns</h2>@foreach($order->refunds as $refund)<p>Request #{{ $refund->id }} · {{ $refund->label() }}@if($refund->decision_note)<br>{{ $refund->decision_note }}@endif</p>@endforeach
@if(\App\Models\Refund::remainingForOrder($order)>=100 && !$order->refunds->whereIn('status',['requested','approved','pending','processing'])->count())<form class="stack" method="post" action="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('guest.orders.refunds',\Carbon\Carbon::createFromTimestamp((int)request('expires')),['order'=>$order->id]) }}">@csrf<label>Reason for your refund request<textarea name="reason" required minlength="10" maxlength="2000" rows="3"></textarea></label><button class="button">Send request for store review</button></form>@endif</section>@endif
@endsection
