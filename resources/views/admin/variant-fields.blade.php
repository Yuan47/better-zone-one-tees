@if($variant)<p class="muted">SKU: <b>{{ $variant->sku }}</b></p>@else<p class="muted">Choose the size and color, then enter its prices and counted stock. SKU is assigned automatically.</p>@endif
@php
    $selectedSize=strtoupper((string)$inputValue('size',$variant?->size??''));
@endphp
<div class="two-col"><label>Size *<select name="size" required><option value="">Choose size</option>@foreach(\App\Services\VariantSizes::options($product,$variant,$selectedSize) as $size)<option value="{{ $size['value'] }}" @selected(\App\Services\VariantSizes::label($selectedSize)===$size['label'])>{{ $size['label'] }}</option>@endforeach</select></label><label>Color *<input name="color" required value="{{ $inputValue('color',$variant?->color) }}" maxlength="80" list="product-colors" placeholder="e.g. White or Red"></label></div>
<fieldset class="catalog-price-fields"><legend>Price per piece (₱)</legend><div class="three-col">@foreach(['retail'=>'Retail / SRP','wholesale'=>'Wholesale · 6–50 pieces','reseller'=>'Re-seller · 51–100 pieces'] as $key=>$label)<label>{{ $label }}<input type="number" name="{{ $key }}" required min="1" max="100000" step=".01" value="{{ $inputValue($key,$variant?->{$key.'_price'}? $variant->{$key.'_price'}/100 : '') }}"></label>@endforeach</div></fieldset>
@php
    $stockChanged=$variant && $inputValue('expected_stock')!==null && (int)$inputValue('expected_stock')!==(int)$variant->stock;
@endphp
@if($variant)<input type="hidden" name="expected_stock" value="{{ $variant->stock }}">@endif
<div class="two-col"><label>On-hand stock<input type="number" name="stock" required min="0" max="1000000" value="{{ $stockChanged?$variant->stock:$inputValue('stock',$variant?->stock??0) }}"></label><label>Low-stock alert at<input type="number" name="reorder_level" required min="0" max="1000000" value="{{ $inputValue('reorder_level',$variant?->reorder_level??10) }}"></label></div>
@include('admin.partials.photo-upload',['photoVariant'=>$variant,'currentPhotoPath'=>$variant?->image_path,'removeChecked'=>$inputValue('remove_photo',false),'photoLabel'=>'Variant photo (optional)','saveLabel'=>$variant?'Save variant':'Add variant'])
