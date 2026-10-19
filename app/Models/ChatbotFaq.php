<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotFaq extends Model
{
    public const AUDIENCES = ['customer', 'staff', 'owner'];

    protected $attributes = ['audience' => 'customer'];

    protected $fillable = ['question', 'answer', 'keywords', 'active', 'sort_order', 'audience'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }
}
