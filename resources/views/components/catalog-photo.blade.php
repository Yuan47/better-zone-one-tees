@props(['product', 'variant' => null, 'imageId' => null, 'loading' => 'lazy'])
@php
    $photo = $variant ? $variant->photoUrl($product) : $product->photoUrl();
    $demo = $variant ? $variant->usesDemoPhoto($product) : $product->usesDemoPhoto();
    $color = $variant ? $variant->photoColor() : $product->photoColor();
@endphp
<span {{ $attributes->class(['catalog-photo', 'demo-photo' => $demo]) }} data-catalog-photo
    @if($demo) style="--garment-photo:url('{{ $photo }}');--garment-color:{{ $color }}" @endif>
    <img @if($imageId) id="{{ $imageId }}" @endif @if($photo) src="{{ $photo }}" @endif alt="{{ $product->name }}{{ $variant ? ' · '.$variant->color : '' }}{{ $demo ? ' · demonstration preview' : '' }}" loading="{{ $loading }}" @if(!$photo) hidden @endif>
    @if(!$photo && !$imageId)<span class="photo-pending">Photo to follow</span>@endif
</span>
