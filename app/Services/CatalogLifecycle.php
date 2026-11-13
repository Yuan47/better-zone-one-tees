<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogLifecycle
{
    public function archiveProduct(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked->variants()->orderBy('id')->lockForUpdate()->get();
            if (! $locked->archived_at) {
                $locked->update(['archived_at' => now(), 'archive_was_active' => $locked->active, 'active' => false]);
            }

            return $locked;
        }, 3);
    }

    public function restoreProduct(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($locked->archived_at) {
                $locked->update(['archived_at' => null, 'active' => $locked->archive_was_active ?? true, 'archive_was_active' => null]);
            }

            return $locked;
        }, 3);
    }

    public function archiveVariant(Product $product, Variant $variant): Variant
    {
        return DB::transaction(function () use ($product, $variant) {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked = $product->variants()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            if (! $locked->archived_at) {
                $locked->update(['archived_at' => now()]);
            }

            return $locked;
        }, 3);
    }

    public function restoreVariant(Product $product, Variant $variant): Variant
    {
        return DB::transaction(function () use ($product, $variant) {
            $lockedProduct = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($lockedProduct->archived_at) {
                throw ValidationException::withMessages(['catalog' => 'Restore the product before restoring one of its variants.']);
            }
            $locked = $product->variants()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $locked->update(['archived_at' => null]);

            return $locked;
        }, 3);
    }

    public function deleteVariant(Product $product, Variant $variant): void
    {
        $photo = DB::transaction(function () use ($product, $variant) {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked = $product->variants()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $this->assertDeletable($locked);
            $locked->delete();

            return $locked->image_path;
        }, 3);
        app(ImageUploads::class)->delete($photo);
    }

    public function deleteProduct(Product $product): void
    {
        $photos = DB::transaction(function () use ($product) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (! $locked->archived_at) {
                throw ValidationException::withMessages(['catalog' => 'Archive this product before deleting it permanently.']);
            }
            $variants = $locked->variants()->orderBy('id')->lockForUpdate()->get();
            foreach ($variants as $variant) {
                $this->assertUnreferenced($variant);
            }
            $photos = $variants->pluck('image_path')->push($locked->image_path)->filter()->unique()->all();
            $locked->variants()->delete();
            $locked->delete();

            return $photos;
        }, 3);
        foreach ($photos as $photo) {
            app(ImageUploads::class)->delete($photo);
        }
    }

    private function assertDeletable(Variant $variant): void
    {
        if (! $variant->archived_at) {
            throw ValidationException::withMessages(['catalog' => 'Archive this variant before deleting it permanently.']);
        }
        $this->assertUnreferenced($variant);
    }

    private function assertUnreferenced(Variant $variant): void
    {
        if ($variant->reserved > 0) {
            throw ValidationException::withMessages(['catalog' => 'An open order reserves this variant. Keep it archived until the order is resolved.']);
        }
        if (OrderItem::where('variant_id', $variant->id)->exists()) {
            throw ValidationException::withMessages(['catalog' => 'This item belongs to an order record. Keep it archived so invoices, payments and sales history remain traceable.']);
        }
    }
}
