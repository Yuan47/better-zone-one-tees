<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RefundVerification
{
    public function check(Refund $refund, ?string $reference = null, ?int $paymentAttemptId = null): Refund
    {
        $refund = $refund->fresh(['order']);
        if ($reference !== null && ! $refund->order->deliveryConfirmed()) {
            $this->fail('Refund processing is available only after customer-confirmed delivery.');
        }
        $this->source($refund->order, $refund, $paymentAttemptId);
        $id = $reference ?? $refund->provider_refund_id;
        if (! app(PayMongo::class)->ready() || ! is_string($id) || ! preg_match('/^ref_[A-Za-z0-9_]{1,120}$/D', $id)) {
            $this->fail('Configure PayMongo and supply a valid refund reference before verification.');
        }
        $data = Http::withBasicAuth(config('shop.paymongo.secret'), '')->connectTimeout(5)->timeout(20)
            ->get('https://api.paymongo.com/refunds/'.$id)->throw()->json('data');

        return DB::transaction(function () use ($refund, $id, $data, $paymentAttemptId) {
            // All refund requests for an order serialize against the same order lock.
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (! $locked->provider_refund_id && ! $order->deliveryConfirmed()) {
                $this->fail('Refund processing is available only after customer-confirmed delivery.');
            }
            $source = $this->source($order, $locked, $paymentAttemptId, true);
            $expectedPayment = $source?->payment_id ?? $order->payment_id;
            $receivedAt = $source?->received_at ?? $order->paid_at;
            $amount = data_get($data, 'attributes.amount');
            $status = data_get($data, 'attributes.status');
            if ($order->payment_status !== 'paid' || ! $expectedPayment || data_get($data, 'id') !== $id
                || data_get($data, 'attributes.payment_id') !== $expectedPayment
                || data_get($data, 'attributes.currency') !== 'PHP'
                || data_get($data, 'attributes.livemode') !== (config('shop.paymongo.mode') === 'live')
                || ! is_int($amount) || $amount < 100 || $amount > ($locked->approved_amount ?? 0)
                || ! in_array($status, ['pending', 'processing', 'succeeded', 'failed'], true)) {
                $this->fail('The refund reference, payment, amount or mode does not match this approved request. No refund was confirmed.');
            }
            if (! in_array($locked->status, ['approved', 'pending', 'processing', 'succeeded', 'failed'], true)
                || ($locked->provider_refund_id && $locked->provider_refund_id !== $id)) {
                $this->fail('This request cannot attach another refund reference.');
            }
            if (Refund::where('provider_refund_id', $id)->whereKeyNot($locked->id)->exists()) {
                $this->fail('This refund reference is already attached to another request.');
            }
            $timestamp = data_get($data, 'attributes.updated_at');
            if (! is_int($timestamp) || $timestamp < 1 || $timestamp > time()
                || ! $receivedAt || $timestamp < $receivedAt->timestamp) {
                $this->fail('The refund update date could not be verified.');
            }
            // A delayed provider read must not overwrite a newer saved outcome.
            if ($locked->provider_updated_at !== null && $timestamp < $locked->provider_updated_at) {
                return $locked;
            }
            if ($locked->provider_refund_id && $locked->amount !== $amount) {
                $this->fail('The provider response changes the amount of the attached refund.');
            }
            if ($locked->provider_updated_at === $timestamp) {
                if ($locked->status !== $status || $locked->amount !== $amount) {
                    $this->fail('The provider response conflicts with the saved refund update.');
                }

                return $locked;
            }
            if ((in_array($locked->status, ['succeeded', 'failed'], true) && $status !== $locked->status)
                || ($locked->status === 'processing' && $status === 'pending')) {
                $this->fail('The provider response conflicts with the saved refund outcome.');
            }
            if ($amount > Refund::remainingForOrder($order, $locked->id)
                || ($source && $amount > Refund::remainingForPayment($order, $source, $locked->id))) {
                $this->fail('The combined refund amount exceeds the payment.');
            }
            $updates = ['provider_refund_id' => $id, 'amount' => $amount, 'status' => $status,
                'provider_updated_at' => $timestamp,
                'confirmed_at' => $status === 'succeeded' ? ($locked->confirmed_at ?? Carbon::createFromTimestamp($timestamp, 'Asia/Manila')) : null];
            if ($source && ($paymentAttemptId !== null || $locked->payment_attempt_id !== null)) {
                $updates['payment_attempt_id'] = $source->id;
            }
            $locked->update($updates);

            return $locked;
        }, 3);
    }

    private function source(Order $order, Refund $refund, ?int $requestedId, bool $lock = false): ?PaymentAttempt
    {
        if ($refund->payment_attempt_id !== null && $requestedId !== null && $refund->payment_attempt_id !== $requestedId) {
            $this->fail('The selected payment receipt differs from the approved refund source.');
        }
        $sourceId = $refund->payment_attempt_id ?? $requestedId;
        $query = PaymentAttempt::where('order_id', $order->id)->where('status', 'paid');
        if ($sourceId !== null) {
            $query->whereKey($sourceId);
        } elseif (! (clone $query)->exists()) {
            return null;
        } elseif ($refund->provider_refund_id) {
            // An already attached legacy refund keeps its original compatibility match.
            $query->where('method', 'paymongo')->where('payment_id', $order->payment_id);
        } else {
            $this->fail('Choose the paid PayMongo receipt for this refund.');
        }
        $source = $query->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $source || $source->method !== 'paymongo' || ! $source->received_at || ! is_string($source->payment_id)
            || ! str_starts_with($source->payment_id, 'pay_') || $source->amount < 100) {
            $this->fail('Choose a verified PayMongo receipt belonging to this invoice. Cash returns must be recorded separately by the owner.');
        }

        return $source;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['refund' => $message]);
    }
}
