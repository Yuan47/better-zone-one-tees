<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Variant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentRecovery
{
    public function __construct(private PayMongo $pay, private OrderFlow $flow) {}

    public function check(Order $order, bool $close = false): string
    {
        if ($order->payments()->exists()) {
            return app(InvoicePayments::class)->check($order, $close);
        }
        $order = $order->fresh();
        if ($order->payment_status === 'paid') {
            return 'paid';
        }
        if ($order->inventory_released) {
            return 'cancelled';
        }
        if (! $order->checkout_session_id) {
            $this->fail('The store owner must recover the checkout reference from PayMongo before this order can be checked or closed.');
        }
        $session = $this->pay->retrieve($order->checkout_session_id);
        $this->validateSession($order, $session, $order->checkout_session_id);
        $result = $this->apply($order, $session);
        if ($result !== 'pending' || ! $close) {
            return $result;
        }
        // Expiration alone is insufficient: fetch again and inspect payments and intent.
        $this->pay->expire($order->checkout_session_id);
        $session = $this->pay->retrieve($order->checkout_session_id);
        $this->validateSession($order, $session, $order->checkout_session_id);

        return $this->apply($order, $session);
    }

    public function recover(Order $order, string $id): string
    {
        if ($order->payments()->exists()) {
            return app(InvoicePayments::class)->recover($order, $id);
        }
        $session = $this->pay->retrieve($id);
        $this->validateSession($order, $session, $id);
        DB::transaction(function () use ($order, $id, $session) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->checkout_session_id || $locked->inventory_released || $locked->payment_status !== 'pending') {
                $this->fail('This order already has a checkout reference or no longer needs recovery.');
            }
            if (Order::where('checkout_session_id', $id)->whereKeyNot($order->id)->exists()) {
                $this->fail('This checkout reference belongs to another order.');
            }
            $url = data_get($session, 'attributes.checkout_url');
            $locked->update(['checkout_session_id' => $id, 'checkout_url' => is_string($url) && str_starts_with($url, 'https://checkout.paymongo.com/') ? $url : null]);
        }, 3);

        return $this->apply($order->fresh(), $session);
    }

    private function validateSession(Order $order, array $session, string $id): void
    {
        if (($session['id'] ?? null) !== $id || data_get($session, 'attributes.reference_number') !== $order->number
            || data_get($session, 'attributes.livemode') !== (config('shop.paymongo.mode') === 'live')) {
            $this->fail('The checkout reference, order number, or payment mode does not match. Inventory remains reserved.');
        }
        $lines = data_get($session, 'attributes.line_items');
        if (! is_array($lines) || ! count($lines)) {
            $this->fail('The checkout total could not be verified. Inventory remains reserved.');
        }
        $sum = 0;
        foreach ($lines as $line) {
            if (($line['currency'] ?? null) !== 'PHP' || ! is_int($line['amount'] ?? null) || ! is_int($line['quantity'] ?? null)
                || $line['amount'] < 0 || $line['quantity'] < 1) {
                $this->fail('The checkout total could not be verified. Inventory remains reserved.');
            }
            $sum += $line['amount'] * $line['quantity'];
        }
        if ($sum !== $order->total) {
            $this->fail('The checkout amount differs from this order. Inventory remains reserved.');
        }
    }

    private function apply(Order $order, array $session): string
    {
        return DB::transaction(function () use ($order, $session) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_status === 'paid') {
                return 'paid';
            }
            if ($locked->inventory_released) {
                return 'cancelled';
            }
            if ($locked->checkout_session_id !== $session['id']) {
                $this->fail('The checkout reference changed; check again.');
            }
            $payments = data_get($session, 'attributes.payments');
            if (! is_array($payments)) {
                $this->fail('Payment details are unavailable. Inventory remains reserved.');
            }
            foreach ($payments as $payment) {
                if (data_get($payment, 'attributes.status') !== 'paid') {
                    continue;
                }
                if (data_get($payment, 'attributes.currency') !== 'PHP' || data_get($payment, 'attributes.amount') !== $locked->total
                    || data_get($payment, 'attributes.livemode') !== (config('shop.paymongo.mode') === 'live')
                    || ! is_string($payment['id'] ?? null) || ! preg_match('/^pay_[A-Za-z0-9_]+$/', $payment['id'])) {
                    $this->fail('A payment needs review because its details do not match. Inventory remains reserved.');
                }
                $timestamp = data_get($payment, 'attributes.paid_at');
                if ($timestamp !== null && (! is_int($timestamp) || $timestamp < 1 || $timestamp > now()->timestamp + 300 || $timestamp < $locked->created_at->timestamp - 300)) {
                    $this->fail('The payment date could not be verified. Inventory remains reserved.');
                }
                $this->flow->paid($locked, $payment['id'], $timestamp ? Carbon::createFromTimestamp($timestamp, 'Asia/Manila') : null);

                return 'paid';
            }
            $intent = data_get($session, 'attributes.payment_intent.attributes.status');
            if (data_get($session, 'attributes.status') !== 'expired') {
                return 'pending';
            }
            foreach ($payments as $payment) {
                if (! in_array(data_get($payment, 'attributes.status'), ['failed', 'cancelled'], true)) {
                    $this->fail('A payment attempt is unsettled. Inventory remains reserved until PayMongo verifies its outcome.');
                }
            }
            // Never release while a payment may be processing or awaiting authentication.
            $neverStarted = array_key_exists('payment_intent', $session['attributes']) && $session['attributes']['payment_intent'] === null;
            if (! $neverStarted && ! in_array($intent, ['awaiting_payment_method', 'cancelled'], true)) {
                $this->fail('Checkout is closed, but payment processing still needs verification. Inventory remains reserved.');
            }
            foreach ($locked->items()->orderBy('variant_id')->get() as $item) {
                $variant = Variant::whereKey($item->variant_id)->lockForUpdate()->firstOrFail();
                if ($variant->reserved < $item->quantity) {
                    $this->fail('The stock reservation needs review. No stock was released.');
                }
                $variant->decrement('reserved', $item->quantity);
            }
            $locked->update(['status' => 'cancelled', 'inventory_released' => true, 'checkout_url' => null]);

            return 'cancelled';
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
