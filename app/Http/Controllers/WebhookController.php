<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $outcome = DB::transaction(function () use ($eventId, $type, $session, $flow) {
            $order = Order::where('checkout_session_id', $session['id'])->lockForUpdate()->first();
            if (! $order) {
                return 'unknown_order';
            }
            if (DB::table('webhook_events')->where('event_id', $eventId)->exists()) {
                return 'duplicate';
            }
            $payment = collect(data_get($session, 'attributes.payments', []))->first(function ($p) use ($order) {
                return data_get($p, 'attributes.status') === 'paid' && data_get($p, 'attributes.currency') === 'PHP'
                    && data_get($p, 'attributes.amount') === $order->total && is_string($p['id'] ?? null);
            });
            if (! $payment || data_get($session, 'attributes.reference_number') !== $order->number) {
                return 'mismatch';
            }
            $flow->paid($order, $payment['id']);
            DB::table('webhook_events')->insert(['event_id' => $eventId, 'type' => $type, 'outcome' => 'paid', 'created_at' => now(), 'updated_at' => now()]);

            return 'paid';
        }, 3);

        // Retry unknown sessions: the webhook can race the checkout-session save.
        return response()->json(['received' => in_array($outcome, ['paid', 'duplicate']), 'outcome' => $outcome], in_array($outcome, ['paid', 'duplicate']) ? 200 : 409);
    }
}
