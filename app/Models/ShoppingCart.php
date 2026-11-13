<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoppingCart extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
