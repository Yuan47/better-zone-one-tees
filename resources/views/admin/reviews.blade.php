@extends('layout')
@section('title','Customer reviews')
@section('crumb','Customer reviews')
@section('content')
<div class="section-heading"><div><span class="eyebrow">CUSTOMER FEEDBACK</span><h1>Customer reviews</h1><p class="muted">Read ratings submitted when customers confirmed receipt of their fully paid orders. Written reviews are optional.</p></div><a class="button" href="{{ route('admin.orders') }}">View orders</a></div>
<div class="notice">Each rating covers the whole order and appears once here, including orders with several products. Product pages show that same order rating for the items purchased. Refund proof stays private and is not a review attachment.</div>
<form class="filters">
@foreach(['sort','direction'] as $sortField)
    @if(request()->filled($sortField))<input type="hidden" name="{{ $sortField }}" value="{{ request($sortField) }}">@endif
@endforeach
<label>Product<select name="product_id"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)request('product_id')===(string)$product->id)>{{ $product->name }}</option>@endforeach</select></label>
<label>Rating<select name="rating"><option value="">All ratings</option>@foreach([5,4,3,2,1] as $rating)<option value="{{ $rating }}" @selected((string)request('rating')===(string)$rating)>{{ $rating }} {{ $rating===1?'star':'stars' }}</option>@endforeach</select></label>
<button class="button small">Filter</button><a class="text-link" href="{{ route('admin.reviews.index') }}">Clear filters</a></form>
<p class="muted">{{ number_format($reviewCount) }} matching {{ $reviewCount===1?'review':'reviews' }}
@if($reviewCount>0)
 · Average order rating: {{ number_format($averageRating,1) }} / 5
@endif
</p>
<div class="panel table-scroll"><table data-server-sort><thead><tr><x-sort-header column="date" type="date">Date</x-sort-header><x-sort-header column="product">Purchased products</x-sort-header><x-sort-header column="customer">Customer</x-sort-header><x-sort-header column="rating" type="number">Rating</x-sort-header><x-sort-header column="review">Review</x-sort-header><x-sort-header column="order">Order</x-sort-header></tr></thead><tbody>
@forelse($reviews as $review)
<tr>
<td>{{ $review->created_at->format('M d, Y') }}</td>
<td>@foreach($review->order->items->groupBy(fn($item)=>$item->variant->product_id)->sortBy(fn($items)=>$items->first()->variant->product->name) as $items)@php($product=$items->first()->variant->product)<p><b>{{ $product->name }}</b><small>{{ $items->map(fn($item)=>$item->color.' / '.$item->size)->unique()->implode(', ') }}</small></p>@endforeach</td>
<td>{{ $review->buyerLabel() }}</td>
<td><span role="img" aria-label="{{ $review->rating }} out of 5 stars"><span aria-hidden="true">{{ str_repeat('★',$review->rating) }}{{ str_repeat('☆',5-$review->rating) }}</span></span><small>{{ $review->rating }} / 5 · Order rating</small></td>
<td>@if(trim($review->comment)!=='')<p class="preserve-lines">{{ $review->comment }}</p>@else<span class="muted">Star rating only; no written review.</span>@endif</td>
<td><a class="text-link" href="{{ route('orders.show',$review->order) }}">{{ $review->order->number }} →</a></td>
</tr>
@empty<tr><td colspan="6" class="empty-note">No customer reviews match these filters.</td></tr>@endforelse
</tbody></table></div>{{ $reviews->links('pagination') }}
<p class="muted">Reviews are read-only here. A refund does not remove a customer's feedback.</p>
@endsection
