@extends('layout')
@section('title',$product->name)
@section('content')
<div class="breadcrumb"><a href="{{ route('store') }}">Shop all</a> / {{ $product->name }}</div>
@php($variantData=$product->variants->map(fn($v)=>['id'=>$v->id,'size'=>$v->size,'color'=>$v->color,'group'=>$v->color_group,'stock'=>$v->available(),'retail'=>$v->retail_price,'wholesale'=>$v->wholesale_price,'reseller'=>$v->reseller_price,'photo'=>$v->photoUrl(),'sku'=>$v->sku])->values())
@php($colorGroups=$product->variants->pluck('color_group')->unique()->values())
@php($styleOptions=$product->variants->isNotEmpty() && $product->variants->every(fn($variant)=>in_array($variant->color,['Plain','Reflector'],true)))
<div class="product-detail"><div class="detail-art">
<img id="selected-product-photo" src="{{ $product->photoUrl()??'' }}" alt="{{ $product->name }}" @if(!$product->photoUrl()) hidden @endif>
<div id="product-photo-placeholder" @if($product->photoUrl()) hidden @endif><x-shirt :color="$product->art_color" :polo="$product->isPolo()"/><span class="art-caption">PHOTO PENDING · SAMPLE ILLUSTRATION</span></div>
</div><div class="detail-copy"><span class="eyebrow">{{ $styleOptions?'CHOOSE YOUR STYLE AND SIZE':($colorGroups->count()>1?'WHITE + COLORED · MIX YOUR SIZES':'CHOOSE YOUR COLOR AND SIZE') }}</span><h1>{{ $product->name }}</h1><p class="detail-price" id="selected-price">From ₱{{ number_format(($product->variants->min('retail_price')??0)/100,2) }}<small> / piece · SRP</small></p><p>{{ $product->description }}</p>
<form action="{{ route('cart.add') }}" method="post" class="stack" id="product-cart-form">@csrf
<div @class(['two-col'=>$colorGroups->count()>1])><label @if($colorGroups->count()<2) hidden @endif>Color group<select id="color-group">@foreach($colorGroups as $colorGroup)<option>{{ $colorGroup }}</option>@endforeach</select></label><label>{{ $styleOptions?'Style':'Color' }}<select id="variant-color"></select></label></div>
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
@endsection
