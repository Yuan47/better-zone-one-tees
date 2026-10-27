<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variant extends Model
{
    protected $guarded = ['id'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function photoUrl(): ?string
    {
        return $this->image_path ? asset($this->image_path) : $this->product->photoUrl();
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
