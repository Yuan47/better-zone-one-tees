@extends('layout')
@section('title','Customers')
@section('crumb','Customers')
@section('content')
<div class="page-heading"><span class="eyebrow">THE PEOPLE BEHIND YOUR STORE</span><h1>Customers</h1><p class="muted">Registered accounts. Prices are based on order quantity for everyone.</p></div><div class="panel table-scroll"><table><thead><tr><th>Name</th><th>Email</th><th>Joined</th></tr></thead><tbody>@foreach($customers as $customer)<tr><td>{{ $customer->name }}</td><td>{{ $customer->email }}</td><td>{{ $customer->created_at->format('M d, Y') }}</td></tr>@endforeach</tbody></table></div>{{ $customers->links('pagination') }}
@endsection