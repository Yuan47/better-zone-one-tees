@extends('layout')
@section('title','Refund requests')
@section('crumb','Refunds')
@section('content')
<div class="section-heading"><div><span class="eyebrow">CUSTOMER CARE</span><h1>Refund requests</h1><p class="muted">Review requests and check provider outcomes. Only PayMongo-confirmed refunds count as completed.</p></div><a class="button" href="{{ route('admin.operations') }}">Order & delivery overview</a></div>
<form class="filters"><label>Order number<input name="q" value="{{ request('q') }}" maxlength="100" placeholder="ZOT-…"></label><label>Refund status<select name="status"><option value="">All statuses</option>@foreach(\App\Models\Refund::STATUSES as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ ucfirst(str_replace('_',' ',$status)) }}</option>@endforeach</select></label><button class="button small">Filter</button><a class="text-link" href="{{ route('admin.refunds.index') }}">Clear filters</a></form>
<div class="panel table-scroll"><table><thead><tr><th>Request</th><th>Order / customer</th><th>Requested</th><th>Provider amount</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($refunds as $refund)<tr><td>#{{ $refund->id }}<small>{{ $refund->created_at->format('M d, Y') }}</small></td><td>{{ $refund->order->number }}<small>{{ $refund->order->user->name }}</small></td><td>₱{{ number_format($refund->requested_amount/100,2) }}</td><td>{{ $refund->amount===null?'Not verified':'₱'.number_format($refund->amount/100,2) }}</td><td><span class="badge">{{ $refund->label() }}</span></td><td><a class="text-link" href="{{ route('admin.refunds.show',$refund) }}">Review →</a></td></tr>
@empty<tr><td colspan="6" class="empty-note">No refund requests match these filters.</td></tr>@endforelse
</tbody></table></div>{{ $refunds->links('pagination') }}
@endsection
