<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReadyNotification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'provider_status' => 'integer', 'attempted_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function label(): string
    {
        return match ($this->status) {
            'sent' => 'Accepted by Brevo',
            'failed' => 'Not sent · safe to retry after checking email setup',
            'unknown', 'claimed' => 'Email outcome needs review · do not resend',
            'skipped' => 'Order or recipient changed · not sent',
            default => 'Waiting to send',
        };
    }
}
