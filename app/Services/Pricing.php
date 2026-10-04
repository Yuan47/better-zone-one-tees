<?php

namespace App\Services;

use App\Models\Variant;
use Illuminate\Validation\ValidationException;

class Pricing
{
    public const LABELS = ['retail' => 'Retail / SRP', 'wholesale' => 'Wholesale', 'reseller' => 'Re-seller', 'bulk' => 'Bulk'];

    public function tier(int $quantity): string
    {
        return match (true) {
            $quantity >= 101 => 'bulk', $quantity >= 51 => 'reseller', $quantity >= 6 => 'wholesale', default => 'retail'
        };
    }

    public function cart(array $cart): array
    {
        $quantity = array_sum($cart);
        $tier = $this->tier($quantity);
        $lines = [];
        $subtotal = 0;
        $retail = 0;
        foreach (Variant::with('product')->whereIn('id', array_keys($cart))->get()->keyBy('id') as $id => $variant) {
            $qty = (int) $cart[$id];
            if ($qty < 1) {
                continue;
            }
            $baseTier = $tier === 'bulk' ? 'reseller' : $tier;
            $price = (int) $variant->{$baseTier.'_price'};
            $lines[] = ['variant' => $variant, 'quantity' => $qty, 'price' => $price, 'total' => $price * $qty];
            $subtotal += $price * $qty;
            $retail += $variant->retail_price * $qty;
        }
        if (count($lines) !== count($cart)) {
            throw ValidationException::withMessages(['cart' => 'A cart item is no longer available. Please update your cart.']);
        }
        $base = $subtotal;
        $discount = $tier === 'bulk' ? (int) round($base * 0.05) : 0;
        $subtotal = $base - $discount;

        return compact('quantity', 'tier', 'lines', 'subtotal', 'base', 'discount') + ['label' => self::LABELS[$tier], 'savings' => $retail - $subtotal];
    }
}
