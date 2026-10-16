<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Variant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderFlow
{
    public function reserve(array $cart, array $details, ?int $userId): Order
    {
        return DB::transaction(function () use ($cart, $details, $userId) {
            if (($details['payment_plan'] ?? 'full') === 'partial') {
                app(InstallmentCredit::class)->assertAvailable($userId, $details['phone'] ?? null);
                $details['credit_phone_hash'] = InstallmentCredit::phoneHash($details['phone'] ?? null);
            }
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
            $details = array_intersect_key($details, array_flip(['recipient', 'phone', 'address', 'delivery_method', 'lat', 'lng', 'delivery_quote', 'street_address', 'city', 'province', 'postal_code', 'country', 'shipping', 'sales_channel', 'payment_plan', 'due_at', 'creation_key', 'created_by', 'contact_email', 'credit_phone_hash']));
            if (($details['payment_plan'] ?? 'full') === 'partial' && empty($details['due_at'])) {
                $details['due_at'] = now()->addDays(app(InvoiceTerms::class)->days());
            }
            $details['original_due_at'] = $details['due_at'] ?? null;
            $order = Order::create(array_merge($details, ['number' => 'ZOT-'.now()->format('ymd').'-'.strtoupper(Str::random(8)), 'user_id' => $userId,
                'tier' => $price['tier'], 'quantity' => $price['quantity'], 'subtotal' => $price['subtotal'], 'merchandise_base' => $price['base'], 'discount' => $price['discount'], 'total' => $price['subtotal'] + $details['shipping']]));
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
            if ($order->payment_status !== 'pending' || $order->amount_paid > 0 || $order->checkout_session_id || $order->payments()->whereIn('status', PaymentAttempt::ACTIVE)->exists()) {
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

    public function paid(Order $order, string $paymentId, ?CarbonInterface $paidAt = null): void
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
        $order->update(['payment_status' => 'paid', 'amount_paid' => $order->total, 'payment_id' => $paymentId, 'paid_at' => $paidAt ?? now(), 'status' => 'packing', 'inventory_committed' => true]);
    }
}
