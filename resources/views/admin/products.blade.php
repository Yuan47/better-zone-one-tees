@extends('layout')
@section('title','Products & inventory')
@section('crumb','Products & inventory')
@section('content')
<div class="section-heading"><div><span class="eyebrow">YOUR COLLECTION</span><h1>Products & inventory</h1><p class="muted">Manage photos, WHITE and COLORED variants, prices, and stock.</p></div><a class="button dark" href="{{ route('admin.products.create') }}">+ Add product</a></div>
<form class="filters"><input name="q" aria-label="Search products" placeholder="Search products…" value="{{ request('q') }}"><label class="check"><input type="checkbox" name="hidden" value="1" @checked(request('hidden'))> Include hidden products</label><button class="button small">Search</button></form>
<div class="panel table-scroll"><table><thead><tr><th>Product</th><th>Color groups</th><th>Variants</th><th>Available</th><th>Storefront</th><th></th></tr></thead><tbody>@foreach($products as $product)<tr><td><b>{{ $product->name }}</b></td><td>{{ $product->variants->pluck('color_group')->unique()->join(' + ') }}</td><td>{{ $product->variants->count() }}</td><td>{{ $product->variants->sum(fn($v)=>$v->available()) }}</td><td><span class="badge">{{ $product->active?'Active':'Hidden' }}</span></td><td><a class="text-link" href="{{ route('admin.products.edit',$product) }}">Edit →</a></td></tr>@endforeach</tbody></table></div>
@endsection
