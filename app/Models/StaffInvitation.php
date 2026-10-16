<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffInvitation extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'attempts' => 0, 'send_count' => 0];

    protected $hidden = ['password_hash', 'code_hash', 'nonce', 'provider_message_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'resend_at' => 'datetime', 'send_window_started_at' => 'datetime',
            'consumed_at' => 'datetime', 'attempts' => 'integer', 'send_count' => 'integer', 'provider_status' => 'integer'];
    }
}
