<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function scopeVerifiedPurchase(Builder $query): Builder
    {
        return $query->whereBetween('rating', [1, 5])
            ->whereHas('user', fn ($user) => $user->where('role', 'customer'))
            ->whereHas('order', fn ($order) => $order->where('payment_status', 'paid')
                ->whereNotNull('payment_id')->where('status', 'completed')->whereNotNull('customer_confirmed_at')
                ->whereColumn('orders.user_id', 'order_reviews.user_id'));
    }

    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->whereHas('order.items.variant', fn ($variant) => $variant->where('product_id', $productId));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function buyerLabel(): string
    {
        $initial = mb_substr(trim((string) $this->user?->name), 0, 1);

        return $initial === '' ? 'Customer' : $initial.'***';
    }
}
