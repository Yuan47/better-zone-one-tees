@extends('layout')
@section('title','Products & inventory')
@section('crumb','Products & inventory')
@section('content')
<div class="section-heading"><div><span class="eyebrow">YOUR COLLECTION</span><h1>Products & inventory</h1><p class="muted">Set your prices. Keep every size and color in stock.</p></div><a class="button dark" href="{{ route('admin.products.create') }}">+ Add product</a></div>
<form class="filters"><input name="q" aria-label="Search products" placeholder="Search products…" value="{{ request('q') }}"><button class="button small">Search</button></form>
<div class="panel table-scroll"><table><thead><tr><th>Product</th><th>Category</th><th>Variants</th><th>Available</th><th>Reserved</th><th>Storefront</th><th></th></tr></thead><tbody>@foreach($products as $product)<tr><td><b>{{ $product->name }}</b></td><td>{{ $product->category }}</td><td>{{ $product->variants->count() }}</td><td>{{ $product->variants->sum(fn($v)=>$v->available()) }}</td><td>{{ $product->variants->sum('reserved') }}</td><td><span class="badge">{{ $product->active?'Active':'Hidden' }}</span></td><td><a class="text-link" href="{{ route('admin.products.edit',$product) }}">Edit →</a></td></tr>@endforeach</tbody></table></div>
@endsection