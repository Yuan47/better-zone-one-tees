<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\InvoicePayments;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebhookController extends Controller
{
    public function __invoke(Request $r, PayMongo $pay, OrderFlow $flow)
    {
        abort_unless($pay->verify($r->getContent(), (string) $r->header('Paymongo-Signature')), 401);
        $body = $r->json()->all();
        // Both PayMongo's legacy event envelope and its new send.webhook envelope.
        if (isset($body['data']['attributes']['type'])) {
            $eventId = data_get($body, 'data.id');
            $type = data_get($body, 'data.attributes.type');
            $session = data_get($body, 'data.attributes.data');
            $live = data_get($body, 'data.attributes.livemode');
        } else {
            $session = data_get($body, 'data.data');
            $type = data_get($body, 'data.type');
            $live = data_get($body, 'data.livemode');
            $eventId = $body['id'] ?? hash('sha256', $r->getContent());
        }
        abort_unless(is_string($eventId) && strlen($eventId) <= 255 && is_string($type) && strlen($type) <= 255, 422);
        abort_unless(is_bool($live) && $live === (config('shop.paymongo.mode') === 'live'), 422);
        if ($type !== 'checkout_session.payment.paid') {
            return response()->json(['received' => true]);
        }
        abort_unless(is_array($session) && is_string($session['id'] ?? null), 422);
        // Resolve the invoice before the transaction. A plain read inside it
        // would establish an old MySQL snapshot while waiting for its lock.
        $attempt = PaymentAttempt::where('checkout_session_id', $session['id'])->first();
        $outcome = DB::transaction(function () use ($eventId, $type, $session, $flow, $attempt) {
            if ($attempt) {
                $invoice = Order::whereKey($attempt->order_id)->lockForUpdate()->firstOrFail();
                if (DB::table('webhook_events')->where('event_id', $eventId)->lockForUpdate()->first()) {
                    return 'duplicate';
                }
                if (data_get($session, 'attributes.reference_number') !== $invoice->number) {
                    return 'mismatch';
                }
                $payments = collect(data_get($session, 'attributes.payments', []))->filter(fn ($p) => data_get($p, 'attributes.status') === 'paid');
                if ($payments->count() !== 1) {
                    return 'mismatch';
                }
                try {
                    $result = app(InvoicePayments::class)->receiveProvider($attempt, $payments->first(), true);
                } catch (ValidationException $e) {
                    return 'mismatch';
                }
                DB::table('webhook_events')->insert(['event_id' => $eventId, 'type' => $type, 'outcome' => $result, 'created_at' => now(), 'updated_at' => now()]);

                return $result;
            }
            $order = Order::where('checkout_session_id', $session['id'])->lockForUpdate()->first();
            if (! $order) {
                return 'unknown_order';
            }
            if (DB::table('webhook_events')->where('event_id', $eventId)->lockForUpdate()->first()) {
                return 'duplicate';
            }
            $payments = collect(data_get($session, 'attributes.payments', []))->filter(fn ($p) => data_get($p, 'attributes.status') === 'paid');
            $payment = $payments->first();
            if ($payments->count() !== 1 || ! $payment || data_get($session, 'attributes.reference_number') !== $order->number
                || data_get($payment, 'attributes.currency') !== 'PHP' || data_get($payment, 'attributes.amount') !== $order->total
                || ! is_string($payment['id'] ?? null) || ! preg_match('/^pay_[A-Za-z0-9_]+$/D', $payment['id'])
                || (array_key_exists('livemode', $payment['attributes']) && $payment['attributes']['livemode'] !== (config('shop.paymongo.mode') === 'live'))) {
                return 'mismatch';
            }
            $timestamp = data_get($payment, 'attributes.paid_at');
            if ($timestamp !== null && (! is_int($timestamp) || $timestamp < 1 || $timestamp > now()->timestamp + 300 || $timestamp < $order->created_at->timestamp - 300)) {
                return 'mismatch';
            }
            $flow->paid($order, $payment['id'], $timestamp ? Carbon::createFromTimestamp($timestamp, 'Asia/Manila') : null);
            DB::table('webhook_events')->insert(['event_id' => $eventId, 'type' => $type, 'outcome' => 'paid', 'created_at' => now(), 'updated_at' => now()]);

            return 'paid';
        }, 3);

        // Retry unknown sessions: the webhook can race the checkout-session save.
        return response()->json(['received' => in_array($outcome, ['paid', 'partially_paid', 'duplicate']), 'outcome' => $outcome], in_array($outcome, ['paid', 'partially_paid', 'duplicate']) ? 200 : 409);
    }
}
