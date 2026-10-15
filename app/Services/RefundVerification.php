<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Refund;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RefundVerification
{
    public function check(Refund $refund, ?string $reference = null): Refund
    {
        $id = $reference ?? $refund->provider_refund_id;
        if (! app(PayMongo::class)->ready() || ! is_string($id) || ! preg_match('/^ref_[A-Za-z0-9_]{1,120}$/D', $id)) {
            $this->fail('Configure PayMongo and supply a valid refund reference before verification.');
        }
        $data = Http::withBasicAuth(config('shop.paymongo.secret'), '')->connectTimeout(5)->timeout(20)
            ->get('https://api.paymongo.com/refunds/'.$id)->throw()->json('data');

        return DB::transaction(function () use ($refund, $id, $data) {
            // All refund requests for an order serialize against the same order lock.
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $amount = data_get($data, 'attributes.amount');
            $status = data_get($data, 'attributes.status');
            if ($order->payment_status !== 'paid' || ! $order->payment_id || ($data['id'] ?? null) !== $id
                || data_get($data, 'attributes.payment_id') !== $order->payment_id
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
                || ! $order->paid_at || $timestamp < $order->paid_at->timestamp) {
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
            $confirmed = (int) $order->refunds()->where('status', 'succeeded')->whereKeyNot($locked->id)->sum('amount');
            if ($confirmed + $amount > $order->total) {
                $this->fail('The combined refund amount exceeds the payment.');
            }
            $locked->update(['provider_refund_id' => $id, 'amount' => $amount, 'status' => $status,
                'provider_updated_at' => $timestamp,
                'confirmed_at' => $status === 'succeeded' ? ($locked->confirmed_at ?? Carbon::createFromTimestamp($timestamp, 'Asia/Manila')) : null]);

            return $locked;
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['refund' => $message]);
    }
}
