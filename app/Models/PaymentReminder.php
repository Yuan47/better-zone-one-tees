<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReminder extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['claimed', 'sent', 'failed', 'unknown', 'skipped'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'days_before' => 'integer', 'amount' => 'integer', 'provider_status' => 'integer',
            'attempted_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function label(): string
    {
        return match ($this->status) {
            'sent' => 'Accepted by Brevo', 'failed' => 'Email send failed · review required', 'unknown' => 'Email outcome unknown · review required',
            'skipped' => 'Invoice changed · not sent', default => 'Claimed · review if interrupted',
        };
    }
}
