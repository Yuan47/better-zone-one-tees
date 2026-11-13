<?php

namespace App\Models;

use App\Services\DannonProductImages;
use App\Services\CatalogPhotoPreview;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'size_chart' => 'array', 'archived_at' => 'datetime', 'archive_was_active' => 'boolean'];
    }

    public function photoUrl(): ?string
    {
        return CatalogPhotoPreview::product($this) ?? DannonProductImages::url($this->slug, $this->image_path)
            ?? ($this->image_path ? asset($this->image_path) : ($this->image_url ?: null));
    }

    public function usesDemoPhoto(): bool
    {
        return CatalogPhotoPreview::product($this) !== null;
    }

    public function photoColor(): string
    {
        return CatalogPhotoPreview::color($this->art_color);
    }

    public function isPolo(): bool
    {
        return str_contains(strtoupper($this->name), 'WITH COLLAR');
    }

    public function variants()
    {
        return $this->hasMany(Variant::class);
    }

    public function scopePublished($query)
    {
        return $query->where('active', true)->whereNull('archived_at');
    }
}
