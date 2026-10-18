<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnInspection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'clothing_value' => 'integer', 'delivery_value' => 'integer',
            'policy_delivery_eligible' => 'boolean', 'delivered_at' => 'datetime', 'deadline_at' => 'datetime',
            'requested_at' => 'datetime', 'inspected_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('A recorded return inspection is immutable.'));
        static::deleting(fn () => throw new \LogicException('A recorded return inspection cannot be deleted.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function remaining(?int $exceptRefundId = null): array
    {
        $allocated = $this->refunds()->when($exceptRefundId, fn ($query, $id) => $query->whereKeyNot($id))
            ->whereIn('status', ['approved', 'pending', 'processing', 'succeeded'])->get();

        return ['clothing' => max(0, $this->clothing_value - (int) $allocated->sum('clothing_amount')),
            'delivery' => max(0, $this->delivery_value - (int) $allocated->sum('delivery_amount'))];
    }
}
