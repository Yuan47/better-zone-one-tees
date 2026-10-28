<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceiptNotification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer', 'attempts' => 'integer', 'provider_status' => 'integer',
            'last_rejected_status' => 'integer', 'received_at' => 'datetime', 'attempted_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function label(): string
    {
        return match ($this->status) {
            'accepted' => 'Store receipt accepted by Brevo · inbox delivery not confirmed',
            'failed' => 'Store receipt rejected · review email setup before an explicit retry',
            'unknown', 'claimed' => 'Store receipt email outcome needs review · do not resend',
            'skipped' => 'Receipt or recipient changed · email not sent',
            default => 'Store receipt waiting to send',
        };
    }
}
