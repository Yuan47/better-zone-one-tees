<div class="catalog-photo-editor" data-photo-editor>
<div class="upload-row">
@if($photoVariant?->photoUrl($product) ?? $product->photoUrl())<x-catalog-photo :product="$product" :variant="$photoVariant" class="upload-preview-frame"/>@endif
<label>{{ $photoLabel }}<input name="photo" type="file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG, or WebP · up to 5 MB.</small></label>
</div>
@if($currentPhotoPath)
<label class="catalog-photo-remove"><input type="checkbox" name="remove_photo" value="1" @checked($removeChecked)><span>Remove current photo<small>Apply this change with {{ $saveLabel }}. A default preview may still be shown.</small></span></label>
@endif
<p class="catalog-photo-change" data-photo-change role="status" hidden></p>
</div>
