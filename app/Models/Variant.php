<?php

namespace App\Models;

use App\Services\DannonProductImages;
use Illuminate\Database\Eloquent\Model;

class Variant extends Model
{
    protected $guarded = ['id'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function photoUrl(?Product $product = null): ?string
    {
        if ($this->image_path && ! DannonProductImages::isLegacyPreview($this->image_path)) {
            return asset($this->image_path);
        }

        $product = $product !== null && (string) $product->id === (string) $this->product_id ? $product : $this->product;

        return $this->image_path
            ? (DannonProductImages::url($product->slug, $this->image_path) ?? asset($this->image_path))
            : $product->photoUrl();
    }

    public function available(): int
    {
        return max(0, $this->stock - $this->reserved);
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
