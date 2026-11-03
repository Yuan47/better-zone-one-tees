@extends('layout')
@section('title',$product->name)
@section('content')
<div class="breadcrumb"><a href="{{ route('store') }}">Shop all</a> / {{ $product->name }}</div>
@php($variantData=$product->variants->map(fn($v)=>['id'=>$v->id,'size'=>$v->size,'color'=>$v->color,'stock'=>$v->available(),'retail'=>$v->retail_price,'wholesale'=>$v->wholesale_price,'reseller'=>$v->reseller_price,'photo'=>$v->photoUrl(),'sku'=>$v->sku])->values())
@php($styleOptions=$product->variants->isNotEmpty() && $product->variants->every(fn($variant)=>in_array($variant->color,['Plain','Reflector'],true)))
<div class="product-detail"><div class="detail-art">
<img id="selected-product-photo" src="{{ $product->photoUrl()??'' }}" alt="{{ $product->name }}" @if(!$product->photoUrl()) hidden @endif>
<div id="product-photo-placeholder" @if($product->photoUrl()) hidden @endif><x-shirt :color="$product->art_color" :polo="$product->isPolo()"/><span class="art-caption">PHOTO PENDING · SAMPLE ILLUSTRATION</span></div>
</div><div class="detail-copy"><span class="eyebrow">{{ $styleOptions?'CHOOSE YOUR STYLE AND SIZE':'CHOOSE YOUR COLOR AND SIZE' }}</span><h1>{{ $product->name }}</h1><p class="detail-price" id="selected-price">From ₱{{ number_format(($product->variants->min('retail_price')??0)/100,2) }}<small> / piece · SRP</small></p><p>{{ $product->description }}</p>
<form action="{{ route('cart.add') }}" method="post" class="stack" id="product-cart-form">@csrf
<label>{{ $styleOptions?'Style':'Color' }}<select id="variant-color"></select></label>
<label>Size<select id="variant-size"></select></label><input type="hidden" name="variant_id" id="variant-id">
<p class="muted" id="variant-availability"></p><small class="muted" id="variant-sku"></small>
<label>Quantity<input id="variant-quantity" type="number" name="quantity" value="1" min="1" max="10000" required></label><button id="add-to-cart" class="button dark" disabled>Add to cart →</button>
<noscript><p>Enable JavaScript to select your size and color.</p></noscript></form>
<script type="application/json" id="product-variants">{!! json_encode($variantData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<div class="mini-note">↘ Your entire cart gets the matching quantity price.</div>
<div class="panel selected-pricing"><b>Prices for your selected variant</b><table><thead><tr><th>Retail / SRP</th><th>Wholesale · 6–50</th><th>Re-seller · 51–100</th></tr></thead><tbody><tr><td id="price-retail">—</td><td id="price-wholesale">—</td><td id="price-reseller">—</td></tr></tbody></table><small>101+ pieces receive 5% off the Re-seller clothing subtotal. Delivery is separate.</small></div>
</div></div>
<section class="panel size-chart-panel"><div class="section-heading"><div><span class="eyebrow">FIND YOUR FIT</span><h2>Size chart</h2></div><span class="muted">Measurements in inches</span></div>
<div class="table-scroll"><table><thead><tr><th>Size</th><th>Width (in.)</th><th>Length (in.)</th></tr></thead><tbody>@forelse($product->size_chart??[] as $row)<tr><td>{{ $row['size'] }}</td><td>{{ $row['width']??'To be confirmed' }}</td><td>{{ $row['length']??'To be confirmed' }}</td></tr>@empty<tr><td colspan="3">Measurements will be added by the store.</td></tr>@endforelse</tbody></table></div>@if($product->size_chart_note)<p class="muted chart-note">{{ $product->size_chart_note }}</p>@endif</section>
<section class="panel product-reviews" id="product-reviews" aria-labelledby="product-reviews-title">
    <div class="section-heading"><div><span class="eyebrow">FROM OUR CUSTOMERS</span><h2 id="product-reviews-title">Customer reviews</h2></div><span class="muted">{{ number_format($reviewCount) }} {{ $reviewCount===1?'rating':'ratings' }}</span></div>
    <p class="product-review-guide">Ratings are from confirmed purchases containing this item. Each rating covers the whole order; a written review is optional.</p>
    @if($reviewCount>0)
        <div class="product-rating-summary">
            <div class="product-rating-average"><strong>{{ number_format($averageRating,1) }}<small> / 5</small></strong><span>Average order rating</span><small>{{ number_format($reviewCount) }} verified {{ $reviewCount===1?'purchase':'purchases' }}</small></div>
            <div class="product-rating-breakdown" aria-label="Ratings by number of stars">
                @foreach([5,4,3,2,1] as $stars)
                    @php($count=$ratingCounts->get($stars,0))
                    <div class="product-rating-row"><span>{{ $stars }} {{ $stars===1?'star':'stars' }}</span><span class="product-rating-track" aria-hidden="true"><span style="width:{{ round($count/$reviewCount*100,2) }}%"></span></span><strong>{{ number_format($count) }}</strong></div>
                @endforeach
            </div>
        </div>
        <nav class="product-review-filters" aria-label="Filter customer ratings">
            <a href="{{ route('product',$product) }}#product-reviews" @class(['active'=>$ratingFilter===null]) @if($ratingFilter===null) aria-current="true" @endif>All ({{ number_format($reviewCount) }})</a>
            @foreach([5,4,3,2,1] as $stars)
                <a href="{{ route('product',['product'=>$product,'rating'=>$stars]) }}#product-reviews" @class(['active'=>$ratingFilter===$stars]) @if($ratingFilter===$stars) aria-current="true" @endif>{{ $stars }} {{ $stars===1?'star':'stars' }} ({{ number_format($ratingCounts->get($stars,0)) }})</a>
            @endforeach
        </nav>
        <p class="muted product-review-sort">Newest first · Showing {{ number_format($reviews->total()) }} {{ $ratingFilter ? $ratingFilter.'-star ' : '' }}{{ $reviews->total()===1?'rating':'ratings' }}</p>
        <div class="product-review-list">
            @forelse($reviews as $review)
                <article class="product-review-card">
                    <div class="product-review-header"><strong>{{ $review->buyerLabel() }}</strong><span class="product-review-verified">✓ Verified purchase</span><time datetime="{{ $review->created_at->toDateString() }}">{{ $review->created_at->format('M j, Y') }}</time></div>
                    <div class="product-review-stars" role="img" aria-label="{{ $review->rating }} out of 5 stars"><span aria-hidden="true">{{ str_repeat('★',$review->rating) }}<span class="unfilled">{{ str_repeat('☆',5-$review->rating) }}</span></span><small>{{ $review->rating }} / 5 · Order rating</small></div>
                    <p class="product-review-variants">Purchased: {{ $review->order->items->map(fn($item)=>$item->color.' / '.$item->size)->unique()->implode(', ') }}</p>
                    @if(trim($review->comment)!=='')<p class="product-review-comment">{{ $review->comment }}</p>@else<p class="muted product-review-no-comment">This customer left a star rating without a written review.</p>@endif
                </article>
            @empty
                <p class="product-review-empty">No {{ $ratingFilter }}-star ratings yet. <a href="{{ route('product',$product) }}#product-reviews">See all ratings</a>.</p>
            @endforelse
        </div>
        {{ $reviews->links('pagination') }}
    @else
        <div class="product-review-empty"><span aria-hidden="true">☆☆☆☆☆</span><h3>No customer ratings yet</h3><p>After receiving an order, customers can choose their stars and leave an optional review from My Orders.</p></div>
    @endif
</section>
@endsection
