<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
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
