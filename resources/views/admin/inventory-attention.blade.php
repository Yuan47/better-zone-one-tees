@extends('layout')
@section('title', 'Inventory attention')
@section('crumb', 'Inventory attention')
@section('content')
<div class="section-heading"><div><span class="eyebrow">KEEP YOUR STORE STOCKED</span><h1>Inventory attention</h1><p class="muted">Active variants at or below their reorder threshold. Out-of-stock variants appear first, followed by the lowest available quantities; larger threshold shortfalls break ties.</p></div><a class="button" href="{{ route('admin.dashboard') }}">← Store overview</a></div>
<form action="{{ route('admin.inventory-attention') }}" method="get" class="filters" aria-label="Filter inventory shortages">
    <label>Search<input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Product, SKU, size, or color"></label>
    <label>Product<select name="product"><option value="">All active products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) ($filters['product'] ?? '') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></label>
    <label>Availability<select name="availability"><option value="all" @selected(($filters['availability'] ?? 'all') === 'all')>All shortages</option><option value="out_of_stock" @selected(($filters['availability'] ?? '') === 'out_of_stock')>Out of stock</option></select></label>
    <button class="button small" type="submit">Apply filters</button><a class="text-link" href="{{ route('admin.inventory-attention') }}">Clear filters</a>
</form>
<p class="muted">{{ number_format($shortages->total()) }} matching {{ $shortages->total() === 1 ? 'variant' : 'variants' }}. Available quantities exclude stock held for pending orders.</p>
<div class="panel table-scroll"><table><thead><tr><th>Product</th><th>SKU</th><th>Size</th><th>Color</th><th>Available</th><th>Reorder threshold</th><th>Below threshold</th>@if(auth()->user()->role === 'admin')<th>Manage</th>@endif</tr></thead><tbody>
    @forelse($shortages as $variant)<tr><td>{{ $variant->product->name }}</td><td>{{ $variant->sku }}</td><td>{{ $variant->size }}</td><td>{{ $variant->color }}</td><td><span class="badge warning">{{ $variant->available() === 0 ? 'Out of stock' : $variant->available().' pcs' }}</span></td><td>{{ $variant->reorder_level }}</td><td>{{ max(0, $variant->reorder_level - $variant->available()) }}</td>@if(auth()->user()->role === 'admin')<td><a class="text-link" href="{{ route('admin.products.edit', $variant->product) }}">Edit inventory →</a></td>@endif</tr>
    @empty<tr><td colspan="{{ auth()->user()->role === 'admin' ? 8 : 7 }}">No shortages match these filters.</td></tr>@endforelse
</tbody></table></div>
{{ $shortages->links('pagination') }}
@endsection
