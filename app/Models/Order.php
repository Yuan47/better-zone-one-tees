<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = ['id'];

    public const DELIVERY_STAGES = ['packing' => 'Packing', 'ready' => 'Ready for delivery', 'courier' => 'With courier', 'delivered' => 'Delivered'];

    protected function casts(): array
    {
        return ['delivery_quote' => 'array', 'delivery_booking_context' => 'array', 'paid_at' => 'datetime', 'provider_completed_at' => 'datetime',
            'customer_confirmed_at' => 'datetime', 'inventory_committed' => 'boolean', 'inventory_released' => 'boolean'];
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

    public function review()
    {
        return $this->hasOne(OrderReview::class);
    }

    public function customerDeliveryStage(): string
    {
        if ($this->status === 'completed' && $this->customer_confirmed_at) {
            return 'delivered';
        }
        if (in_array($this->status, ['to_pack', 'packing'], true)) {
            return 'packing';
        }
        if ($this->status === 'out_for_delivery'
            || ($this->status === 'completed' && $this->delivery_method === 'lalamove')) {
            return 'courier';
        }

        return 'ready';
    }

    public function customerDeliveryLabel(): string
    {
        return self::DELIVERY_STAGES[$this->customerDeliveryStage()];
    }

    public function scopeWhereDeliveryStage(Builder $query, string $stage): Builder
    {
        $query->where('payment_status', 'paid')->where('status', '!=', 'cancelled');

        return match ($stage) {
            'packing' => $query->whereIn('status', ['to_pack', 'packing']),
            'courier' => $query->where(fn ($query) => $query->where('status', 'out_for_delivery')
                ->orWhere(fn ($query) => $query->where('status', 'completed')->whereNull('customer_confirmed_at')->where('delivery_method', 'lalamove'))),
            'delivered' => $query->where('status', 'completed')->whereNotNull('customer_confirmed_at'),
            'ready' => $query->whereNotIn('status', ['to_pack', 'packing', 'out_for_delivery'])
                ->where(fn ($query) => $query->where('status', '!=', 'completed')
                    ->orWhere(fn ($query) => $query->whereNull('customer_confirmed_at')->where('delivery_method', '!=', 'lalamove'))),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function fulfillmentOnHold(): bool
    {
        return $this->refunds()->whereIn('status', ['pending', 'processing'])->whereNotNull('provider_refund_id')->exists()
            || ($this->status === 'completed' && $this->customer_confirmed_at && $this->refunds()->where('status', 'approved')->exists())
            || ($this->total > 0 && (int) $this->refunds()->where('status', 'succeeded')->sum('amount') >= $this->total);
    }
}
