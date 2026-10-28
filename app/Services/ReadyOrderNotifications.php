<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReadyNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ReadyOrderNotifications
{
    public function ready(): bool
    {
        return config('services.brevo.delivery_notifications_enabled') === true && app(BrevoTransactions::class)->configured();
    }

    /** Called while the order is locked, in the same transaction as its audited packing-to-ready change. */
    public function enqueue(Order $order): void
    {
        if ($this->eligible($order) && ! OrderReadyNotification::where('order_id', $order->id)->exists()) {
            OrderReadyNotification::create(['order_id' => $order->id, 'claim_key' => (string) Str::uuid(), 'status' => 'queued']);
        }
    }

    /** The durable claim commits before HTTP. Never retry an interrupted or uncertain provider request. */
    public function send(int $orderId, ?User $retryActor = null, ?string $reason = null): string
    {
        if (! $this->ready()) {
            return 'unavailable';
        }
        try {
            $claim = DB::transaction(function () use ($orderId, $retryActor, $reason) {
                $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
                $notification = OrderReadyNotification::where('order_id', $orderId)->lockForUpdate()->first();
                $allowed = $retryActor ? ['queued', 'failed'] : ['queued'];
                if (! $notification || ! in_array($notification->status, $allowed, true)) {
                    return null;
                }
                if ($retryActor && (! in_array($retryActor->role, ['staff', 'admin'], true) || ! is_string($reason) || mb_strlen(trim($reason)) < 10)) {
                    return null;
                }
                $order->load('user');
                $recipient = $this->recipient($order);
                if (! $this->eligible($order) || $recipient === null) {
                    $notification->update(['status' => 'skipped', 'failure_reason' => 'order_or_recipient_changed']);

                    return null;
                }
                $name = $order->user?->name ?? $order->recipient;
                $pickup = $order->delivery_method === 'pickup';
                $orderUrl = $order->user_id ? route('orders.show', $order)
                    : URL::temporarySignedRoute('guest.orders.show', now()->addDays(30), ['order' => $order->id]);
                try {
                    $html = view('mail.order-ready', compact('order', 'name', 'pickup', 'orderUrl'))->render();
                } catch (\Throwable $exception) {
                    $notification->update(['status' => 'failed', 'failure_reason' => 'email_render_failed']);

                    return null;
                }
                if ($retryActor && ! app(ActivityRecorder::class)->record($retryActor, 'order.ready_email_retried', $order, ['reason' => $reason])) {
                    throw new \RuntimeException('The notification retry could not be audited.');
                }
                $notification->update(['status' => 'claimed', 'recipient' => $recipient, 'attempts' => $notification->attempts + 1,
                    'attempted_at' => now(), 'provider_status' => null, 'failure_reason' => null]);

                return ['id' => $notification->id, 'payload' => [
                    'to' => [['email' => $recipient, 'name' => BrevoTransactions::recipientName($name)]],
                    'subject' => $order->number.($pickup ? ' is ready for store pickup' : ' is ready for delivery'),
                    'htmlContent' => $html,
                    'textContent' => 'Hello '.$name.', order '.$order->number.($pickup
                        ? ' is fully paid, packed, and ready for collection at the store. Check your order for pickup details.'
                        : ' is fully paid and packed. The store will arrange its courier next. This message does not mean a courier has collected it.')
                        .' View the latest details: '.$orderUrl,
                    'headers' => ['IdempotencyKey' => $notification->claim_key], 'tags' => ['order-ready'],
                ]];
            }, 3);
            if (! $claim) {
                return 'not_sent';
            }

            // Recheck under the order lock, so fulfillment/refunds cannot change during HTTP.
            // The earlier claim is already committed; never retry this network-containing transaction.
            return DB::transaction(function () use ($claim, $orderId) {
                $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
                $notification = OrderReadyNotification::whereKey($claim['id'])->lockForUpdate()->firstOrFail();
                if ($notification->status !== 'claimed') {
                    return 'review';
                }
                $order->load('user');
                if (! $this->ready() || ! $this->eligible($order) || $this->recipient($order) !== $notification->recipient) {
                    $notification->update(['status' => 'skipped', 'failure_reason' => 'order_or_recipient_changed_before_send']);

                    return 'not_sent';
                }
                $result = app(BrevoTransactions::class)->send($claim['payload']);
                // A request timeout (including HTTP 408), server error or malformed acceptance is uncertain.
                $rejected = $result['status'] === 'failed'
                    && in_array($result['provider_status'], [400, 401, 402, 403, 404, 405, 406, 410, 413, 415, 422, 429], true);
                $status = $result['status'] === 'sent' ? 'sent' : ($rejected ? 'failed' : 'unknown');
                if ($status === 'sent' && OrderReadyNotification::where('provider_message_id', $result['message_id'])->whereKeyNot($notification->id)->exists()) {
                    $status = 'unknown';
                }
                $notification->update(['status' => $status, 'provider_status' => $result['provider_status'],
                    'provider_message_id' => $status === 'sent' ? $result['message_id'] : null,
                    'sent_at' => $status === 'sent' ? now() : null,
                    'failure_reason' => match ($status) {
                        'sent' => null, 'failed' => 'provider_rejected_request', default => 'provider_acceptance_not_verified',
                    }]);

                return $status;
            }, 1);
        } catch (\Throwable $exception) {
            // The order transition is already committed. Keep a claimed attempt blocked if persistence fails after HTTP.
            return 'review';
        }
    }

    private function eligible(Order $order): bool
    {
        return $order->status === 'ready' && $order->payment_status === 'paid' && filled($order->payment_id) && $order->paid_at !== null
            && $order->inventory_committed && ! $order->inventory_released && ! $order->delivery_id && ! $order->provider_completed_at
            && ! $order->customer_confirmed_at && ! $order->walk_in_received_at
            && in_array($order->delivery_method, ['pickup', 'lalamove'], true) && ! $order->fulfillmentOnHold();
    }

    private function recipient(Order $order): ?string
    {
        $email = $order->user_id !== null
            ? ($order->user?->role === 'customer' ? $order->user->email : null)
            : ($order->sales_channel === 'walk_in' ? $order->contact_email : null);

        return is_string($email) && strlen($email) <= 254 && ! preg_match('/[\r\n]/', $email)
            && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false ? trim($email) : null;
    }
}
