<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['requested', 'approved', 'rejected', 'pending', 'processing', 'succeeded', 'failed'];

    protected function casts(): array
    {
        return ['requested_amount' => 'integer', 'approved_amount' => 'integer', 'amount' => 'integer', 'provider_updated_at' => 'integer', 'reviewed_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function label(): string
    {
        return match ($this->status) {
            'requested' => 'Awaiting review', 'approved' => 'Approved · awaiting provider refund',
            'rejected' => 'Request declined', 'pending', 'processing' => 'PayMongo processing',
            'succeeded' => 'PayMongo confirmed refund', 'failed' => 'Provider refund failed', default => 'Needs review',
        };
    }
}
