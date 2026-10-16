<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailVerificationCode extends Model
{
    protected $guarded = ['id'];

    // Challenge material must never appear in a serialized API/model response.
    protected $hidden = ['code_hash', 'nonce', 'email'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'resend_at' => 'datetime', 'send_window_started_at' => 'datetime',
            'consumed_at' => 'datetime', 'attempts' => 'integer', 'send_count' => 'integer', 'provider_status' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
