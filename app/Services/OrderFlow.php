<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderFlow
{
    public function reserve(array $cart, array $details, int $userId): Order
    {
        return DB::transaction(function () use ($cart, $details, $userId) {
            // Stable locking order prevents overselling and limits deadlocks.
            $variants = Variant::whereIn('id', array_keys($cart))->orderBy('id')->lockForUpdate()->get();
            $price = app(Pricing::class)->cart($cart);
            if (! $price['quantity']) {
                throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
            }
            foreach ($variants as $variant) {
                if (! $variant->product->active || $variant->available() < $cart[$variant->id]) {
                    throw ValidationException::withMessages(['cart' => $variant->product->name.' has insufficient available stock.']);
                }
            }
            $order = Order::create($details + ['number' => 'ZOT-'.now()->format('ymd').'-'.strtoupper(Str::random(8)), 'user_id' => $userId,
                'tier' => $price['tier'], 'quantity' => $price['quantity'], 'subtotal' => $price['subtotal'], 'merchandise_base' => $price['base'], 'discount' => $price['discount'], 'total' => $price['subtotal'] + $details['shipping']]);
            foreach ($price['lines'] as $line) {
                $v = $line['variant'];
                $qty = $line['quantity'];
                $order->items()->create(['variant_id' => $v->id, 'name' => $v->product->name, 'size' => $v->size, 'color' => $v->color,
                    'quantity' => $qty, 'unit_price' => $line['price'], 'total' => $line['total']]);
                Variant::whereKey($v->id)->increment('reserved', $qty);
            }

            return $order;
        }, 3);
    }

    public function cancel(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_status !== 'pending' || $order->checkout_session_id) {
                throw ValidationException::withMessages(['order' => 'This payment session needs reconciliation before its inventory can be released.']);
            }
            if (! $order->inventory_released) {
                foreach ($order->items()->orderBy('variant_id')->get() as $item) {
                    Variant::whereKey($item->variant_id)->decrement('reserved', $item->quantity);
                }
                $order->update(['status' => 'cancelled', 'inventory_released' => true]);
            }
        }, 3);
    }

    public function paid(Order $order, string $paymentId): void
    {
        // Caller must lock the order in a transaction and verify provider payment first.
        if ($order->inventory_committed) {
            return;
        }
        if ($order->inventory_released || $order->status === 'cancelled') {
            throw new \RuntimeException('Payment requires manual reconciliation');
        }
        foreach ($order->items()->orderBy('variant_id')->get() as $item) {
            $v = Variant::whereKey($item->variant_id)->lockForUpdate()->firstOrFail();
            if ($v->stock < $item->quantity || $v->reserved < $item->quantity) {
                throw new \RuntimeException('Inventory reservation mismatch');
            }
            $v->update(['stock' => $v->stock - $item->quantity, 'reserved' => $v->reserved - $item->quantity]);
        }
        $order->update(['payment_status' => 'paid', 'payment_id' => $paymentId, 'paid_at' => now(), 'status' => 'to_pack', 'inventory_committed' => true]);
    }
}
