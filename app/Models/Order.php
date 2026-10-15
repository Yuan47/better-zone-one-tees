<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['delivery_quote' => 'array', 'delivery_booking_context' => 'array', 'paid_at' => 'datetime', 'inventory_committed' => 'boolean', 'inventory_released' => 'boolean'];
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function fulfillmentOnHold(): bool
    {
        return $this->refunds()->whereIn('status', ['approved', 'pending', 'processing'])->exists()
            || ($this->total > 0 && (int) $this->refunds()->where('status', 'succeeded')->sum('amount') >= $this->total);
    }
}
