<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    protected $guarded = ['id'];

    public const ACTIVE = ['creating', 'pending', 'unknown'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'legacy' => 'boolean', 'received_at' => 'datetime'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function receiptNotification()
    {
        return $this->hasOne(PaymentReceiptNotification::class);
    }
}
