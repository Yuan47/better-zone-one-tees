<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hasDeliveryPin(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }
}
