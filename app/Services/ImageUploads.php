<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageUploads
{
    public function store(UploadedFile $file, string $folder): string
    {
        if (! in_array($folder, ['products', 'variants', 'avatars'], true)) {
            throw new \InvalidArgumentException('Invalid upload folder');
        }

        return 'uploads/'.Storage::disk('uploads')->putFile($folder, $file);
    }

    public function delete(?string $path): void
    {
        if ($path && (Product::where('image_path', $path)->exists() || Variant::where('image_path', $path)->exists() || User::where('avatar_path', $path)->exists())) {
            return;
        }
        if ($path && preg_match('#^uploads/(products|variants|avatars)/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)$#', $path)) {
            Storage::disk('uploads')->delete(substr($path, 8));
        }
    }
}
