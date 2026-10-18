<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleIdentity extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['subject'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
