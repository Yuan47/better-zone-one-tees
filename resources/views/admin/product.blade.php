@extends('layout')
@section('title',$product->exists?'Edit product':'Add product')
@section('crumb','Products / Edit')
@section('content')
@php
    $catalogForm=\App\Support\FormInput::old('catalog_form') ?? (old('size')!==null?'variant':(old('template_color')!==null?'color':'product'));
    $productInput=fn($key,$default=null)=>$catalogForm==='product'?\App\Support\FormInput::old($key,$default):$default;
    $variantInput=fn($key,$default=null)=>$catalogForm==='variant'?\App\Support\FormInput::old($key,$default):$default;
    $colorInput=fn($key,$default=null)=>$catalogForm==='color'?\App\Support\FormInput::old($key,$default):$default;
@endphp
<div class="page-heading"><a class="text-link" href="{{ route('admin.products') }}">← Products & inventory</a><h1>{{ $product->exists?$product->name:'Add a new product' }}</h1></div>
@if($product->exists)@include('admin.partials.catalog-product-actions')@endif
@unless($product->archived_at)
<form class="panel stack catalog-product-form" method="post" enctype="multipart/form-data" action="{{ $product->exists?route('admin.products.save',$product):route('admin.products.store') }}">
@csrf @if($product->exists)@method('PATCH')@endif<input type="hidden" name="catalog_form" value="product">
<h2>Product details</h2>
<div class="two-col"><label>Product name *<input name="name" required maxlength="150" value="{{ $productInput('name',$product->name) }}"></label><label>Storefront status<select name="active"><option value="1" @selected($productInput('active',$product->active??true))>Active</option><option value="0" @selected(!$productInput('active',$product->active??true))>Hidden</option></select></label></div>
<label>Description *<textarea name="description" required rows="3" maxlength="2000">{{ $productInput('description',$product->description) }}</textarea></label>
@include('admin.partials.photo-upload',['photoVariant'=>null,'currentPhotoPath'=>$product->image_path,'removeChecked'=>$productInput('remove_photo',false),'photoLabel'=>'Product photo','saveLabel'=>'Save product'])
<details class="catalog-size-chart" @if($errors->has('size_chart') || $errors->has('size_chart.*')) open @endif><summary>Size chart · measurements in inches</summary><p class="muted">Leave unconfirmed measurements blank.</p>
@php
    $chartRows=$catalogForm==='product'?\App\Support\FormInput::rows('size_chart',$product->size_chart??[['size'=>'','width'=>null,'length'=>null]]):($product->size_chart??[['size'=>'','width'=>null,'length'=>null]]);
@endphp
<div class="table-scroll"><table class="size-chart-editor"><thead><tr><th>Size</th><th>Width (in.)</th><th>Length (in.)</th><th></th></tr></thead><tbody id="size-chart-rows">@foreach(array_values($chartRows) as $i=>$row)<tr><td><input name="size_chart[{{ $i }}][size]" maxlength="40" value="{{ \App\Services\VariantSizes::label($row['size']??'') }}" aria-label="Chart size {{ $i+1 }}"></td><td><input type="number" step=".5" min="1" max="100" name="size_chart[{{ $i }}][width]" value="{{ $row['width']??'' }}" aria-label="Chart width {{ $i+1 }}"></td><td><input type="number" step=".5" min="1" max="100" name="size_chart[{{ $i }}][length]" value="{{ $row['length']??'' }}" aria-label="Chart length {{ $i+1 }}"></td><td><button type="button" class="text-button remove-size-row" aria-label="Remove size row">×</button></td></tr>@endforeach</tbody></table></div>
<button id="add-size-row" type="button" class="button small">+ Add size row</button><label>Size chart note<input name="size_chart_note" maxlength="1000" value="{{ $productInput('size_chart_note',$product->size_chart_note) }}" placeholder="Reference, fit, or measurement notes"></label></details>
<button class="button dark">Save product</button></form>
@endunless
@if($product->exists)
@php
    $adultRanks=array_flip(['TN','XS','S','M','L','XL','2XL','3XL','4XL','5XL']);
    $kids=str_contains(strtoupper($product->name),'KIDS');
    $variantSort=fn($v)=>[mb_strtolower($v->color),$kids?(int)ltrim($v->size,'#'):($adultRanks[preg_replace('/^(\d+)X$/','$1XL',strtoupper($v->size))]??99),$v->size];
    $displayVariants=$product->variants->sortBy($variantSort);
    $variantGroups=$displayVariants->groupBy('color');
    $copyGroups=$displayVariants->whereNull('archived_at')->groupBy('color');
    $colorTemplates=$copyGroups->map(fn($rows)=>$rows->sortBy('id')->unique(fn($v)=>\App\Services\VariantSizes::label($v->size))->sortBy($variantSort)->map(fn($v)=>['size'=>\App\Services\VariantSizes::label($v->size),'reseller'=>$v->reseller_price,'wholesale'=>$v->wholesale_price,'retail'=>$v->retail_price])->values());
@endphp
<div class="section-heading variant-heading"><div><h2>Sizes & colors</h2><p class="muted">Each variant is one size and color, with its own prices and stock.</p></div><span class="badge">{{ $product->variants->whereNull('archived_at')->count() }} active variants</span></div>
<datalist id="product-colors">@foreach($variantGroups->keys() as $color)<option value="{{ $color }}">{{ $color }}</option>@endforeach</datalist>
@unless($product->archived_at)
@unless($editingVariant?->archived_at)
<section class="panel catalog-variant-editor" id="variant-editor"><h3>{{ $editingVariant?'Edit variant · '.\App\Services\VariantSizes::label($editingVariant->size).' / '.$editingVariant->color:'Add a variant' }}</h3>
<form class="stack" method="post" enctype="multipart/form-data" action="{{ $editingVariant?route('admin.variants.save',[$product,$editingVariant]):route('admin.variants.store',$product) }}">
@csrf @if($editingVariant)@method('PATCH')@endif<input type="hidden" name="catalog_form" value="variant">@include('admin.variant-fields',['variant'=>$editingVariant,'inputValue'=>$variantInput])
<div class="catalog-form-actions"><button class="button dark">{{ $editingVariant?'Save variant':'Add variant' }}</button>@if($editingVariant)<a class="text-link" href="{{ route('admin.products.edit',$product) }}#variant-editor">Cancel edit / add another variant</a>@endif</div></form></section>
@endunless
@if(!$editingVariant && $copyGroups->isNotEmpty())
<details class="panel catalog-color-shortcut" @if($catalogForm==='color') open @endif><summary>Add one color in all existing sizes</summary>
<p class="muted">Use this optional shortcut when the new color has the same sizes and prices as an existing color. Otherwise, add individual variants above.</p>
<form class="stack" data-color-shortcut method="post" enctype="multipart/form-data" action="{{ route('admin.colors.store',$product) }}">@csrf<input type="hidden" name="catalog_form" value="color">
<div class="two-col"><label>New color name *<input name="color" required maxlength="80" value="{{ $colorInput('color') }}" placeholder="e.g. Red"></label><label>Sizes and prices to use *<select name="template_color" required><option value="">Choose an existing color</option>@foreach($copyGroups as $color=>$rows)<option value="{{ $color }}" @selected($colorInput('template_color')===$color)>{{ $color }} · {{ $rows->unique('size')->count() }} sizes</option>@endforeach</select></label></div>
<div class="catalog-color-price-preview" data-color-price-preview hidden><p data-color-preview-heading></p><div class="table-scroll"><table><thead><tr><th>Size</th><th>Re-seller</th><th>Wholesale</th><th>Retail / SRP</th></tr></thead><tbody data-color-price-rows></tbody></table></div></div>
<label>Photo for this color (optional)<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG, or WebP · up to 5 MB.</small></label><p class="muted">Every new size starts with zero stock. Enter counted stock before selling it.</p><button class="button">Create size variants</button></form>
<script type="application/json" id="catalog-color-templates">@json($colorTemplates)</script></details>
@endif
@endunless
<section class="panel catalog-variant-list"><div class="section-heading"><h3>Existing variants</h3><label class="variant-filter-label">Show color<select id="admin-color-filter"><option value="">All colors</option>@foreach($variantGroups->keys() as $color)<option value="{{ $color }}">{{ $color }}</option>@endforeach</select></label></div>
<div class="table-scroll"><table><thead><tr><th>Photo</th><th>Size</th><th>Automatic SKU</th><th>Re-seller</th><th>Wholesale</th><th>Retail / SRP</th><th>On hand</th><th>Actions</th></tr></thead>
@forelse($variantGroups as $color=>$rows)<tbody data-variant-color="{{ $color }}"><tr class="catalog-color-heading"><th colspan="8" scope="rowgroup">{{ $color }} <small>{{ $rows->count() }} {{ $rows->count()===1?'variant':'variants' }}</small></th></tr>
@foreach($rows as $variant)<tr><td>@if($variant->photoUrl($product))<x-catalog-photo :product="$product" :variant="$variant" class="variant-thumb-frame"/>@else<span class="muted">Pending</span>@endif</td><td><b>{{ \App\Services\VariantSizes::label($variant->size) }}</b>@if($variant->archived_at)<span class="badge">Archived</span>@endif</td><td><small>{{ $variant->sku }}</small></td><td>₱{{ number_format($variant->reseller_price/100,2) }}</td><td>₱{{ number_format($variant->wholesale_price/100,2) }}</td><td>₱{{ number_format($variant->retail_price/100,2) }}</td><td>{{ $variant->stock }}<div class="variant-inventory-status">@unless($variant->archived_at)@include('admin.partials.inventory-status')@endunless</div></td><td>@if(!$variant->archived_at && !$product->archived_at)<a class="text-link" href="{{ route('admin.products.edit',[$product,'variant'=>$variant->id]) }}#variant-editor">Edit →</a>@endif @include('admin.partials.catalog-variant-actions')</td></tr>@endforeach</tbody>
@empty<tbody><tr><td colspan="8">Add the first size and color above to start this product.</td></tr></tbody>@endforelse
</table></div></section>
@endif
@endsection
