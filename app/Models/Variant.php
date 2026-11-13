<?php

namespace App\Models;

use App\Services\DannonProductImages;
use App\Services\CatalogPhotoPreview;
use Illuminate\Database\Eloquent\Model;

class Variant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function scopeSellable($query)
    {
        return $query->whereNull('archived_at')->whereHas('product', fn ($products) => $products->published());
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function photoUrl(?Product $product = null): ?string
    {
        $product = $product !== null && (string) $product->id === (string) $this->product_id ? $product : $this->product;
        if ($preview = CatalogPhotoPreview::variant($this, $product)) {
            return $preview;
        }
        if ($this->image_path && ! DannonProductImages::isLegacyPreview($this->image_path)) {
            return asset($this->image_path);
        }

        return $this->image_path
            ? (DannonProductImages::url($product->slug, $this->image_path) ?? asset($this->image_path))
            : $product->photoUrl();
    }

    public function usesDemoPhoto(?Product $product = null): bool
    {
        $product = $product !== null && (string) $product->id === (string) $this->product_id ? $product : $this->product;

        return CatalogPhotoPreview::variant($this, $product) !== null;
    }

    public function photoColor(): string
    {
        return CatalogPhotoPreview::namedColor($this->color);
    }

    public function available(): int
    {
        return $this->archived_at ? 0 : max(0, $this->stock - $this->reserved);
    }

    public function inventoryStatus(): string
    {
        $available = $this->available();
        $threshold = max(0, (int) $this->reorder_level);

        if ($available <= intdiv($threshold, 2)) {
            return 'critical';
        }

        return $available <= $threshold ? 'alert' : 'healthy';
    }
}
