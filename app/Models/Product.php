<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'size_chart' => 'array'];
    }

    public function photoUrl(): ?string
    {
        return $this->image_path ? asset($this->image_path) : ($this->image_url ?: null);
    }

    public function isPolo(): bool
    {
        return str_contains(strtoupper($this->name), 'WITH COLLAR');
    }

    public function variants()
    {
        return $this->hasMany(Variant::class);
    }
}
