<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryRecovery
{
    private const ACTIVE = ['ASSIGNING_DRIVER', 'ON_GOING', 'PICKED_UP'];

    private const TERMINAL = ['CANCELED', 'REJECTED', 'EXPIRED'];

    public function __construct(private Lalamove $delivery) {}

    /** Commit the booking claim before the network request, so retries cannot duplicate it. */
    public function beginBooking(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->fulfillmentOnHold()) {
                $this->fail('A refund is approved or processing, or this payment was fully refunded. Review its refund before booking a courier.');
            }
            if ($locked->status !== 'ready' || $locked->payment_status !== 'paid'
                || $locked->delivery_method !== 'lalamove' || $locked->delivery_id
                || $locked->provider_completed_at || $locked->customer_confirmed_at) {
                $this->fail('This order is not eligible for another delivery booking. Refresh or recover its existing delivery first.');
            }
            $locked->update(['status' => 'delivery_booking', 'delivery_booking_context' => $this->snapshot($locked)]);

            return $locked;
        });
    }

    /** Only pre-booking validation failures can release the booking claim. */
    public function rejectBooking(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'delivery_booking' && ! $locked->delivery_id) {
                $locked->update(['status' => 'ready', 'delivery_booking_context' => null]);
            }
        });
    }

    /** A successful POST response belongs to the claimed request; uncertain IDs use recover(). */
    public function acceptBooking(Order $order, array $data): array
    {
        $id = $data['orderId'] ?? null;
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id)) {
            $this->fail('The delivery provider returned an incomplete booking result. Check the original delivery before retrying.');
        }
        $status = $this->providerStatus($data);

        return DB::transaction(function () use ($order, $data, $id, $status) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'delivery_booking' || $locked->delivery_id) {
                $this->fail('The delivery booking changed while the provider was responding. Refresh the existing delivery.');
            }

            return $this->apply($locked, $id, $status, $data);
        });
    }

    public function refresh(Order $order): array
    {
        $order = $order->fresh();
        $this->eligible($order);
        if (! $order->delivery_id || ! in_array($order->status, ['delivery_booking', 'out_for_delivery', 'delivery_attention', 'completed'], true)) {
            $this->fail('There is no attached delivery to refresh. Recover an uncertain booking using its Lalamove delivery ID.');
        }
        $id = $order->delivery_id;
        $data = $this->delivery->track($id);
        $context = $order->delivery_booking_context ?: $this->snapshot($order);
        $status = $this->verify($order, $id, $data, $context);

        return DB::transaction(function () use ($order, $id, $status, $data) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->delivery_id !== $id || ! in_array($locked->status, ['delivery_booking', 'out_for_delivery', 'delivery_attention', 'completed'], true)) {
                $this->fail('The delivery changed during the status check. Refresh the order and try again.');
            }

            return $this->apply($locked, $id, $status, $data);
        });
    }

    public function recover(Order $order, string $id): array
    {
        $order = $order->fresh();
        $this->eligible($order);
        if ($order->status !== 'delivery_booking' || $order->delivery_id || ! $order->delivery_booking_context) {
            $this->fail('Only an uncertain booking with its saved delivery details can be recovered. No new delivery was requested.');
        }
        $context = $order->delivery_booking_context;
        $data = $this->delivery->track($id);
        $status = $this->verify($order, $id, $data, $context);

        return DB::transaction(function () use ($order, $id, $status, $data, $context) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'delivery_booking' || $locked->delivery_id || $locked->delivery_booking_context !== $context) {
                $this->fail('The delivery changed during recovery. Refresh the order before trying again.');
            }

            return $this->apply($locked, $id, $status, $data);
        });
    }

    private function eligible(Order $order): void
    {
        if ($order->payment_status !== 'paid' || $order->delivery_method !== 'lalamove') {
            $this->fail('Only paid Lalamove orders can be checked or recovered.');
        }
    }

    private function snapshot(Order $order): array
    {
        return [
            'mode' => config('shop.lalamove.mode'),
            'stops' => [
                ['address' => (string) config('shop.lalamove.address'), 'coordinates' => ['lat' => (string) config('shop.lalamove.lat'), 'lng' => (string) config('shop.lalamove.lng')], 'name' => 'Zone One Tee’s', 'phone' => (string) config('shop.lalamove.phone')],
                ['address' => $order->address, 'coordinates' => ['lat' => $order->lat, 'lng' => $order->lng], 'name' => $order->recipient, 'phone' => $order->phone],
            ],
        ];
    }

    private function verify(Order $order, string $id, array $data, array $context): string
    {
        if (($data['orderId'] ?? null) !== $id || data_get($data, 'metadata.order_number') !== $order->number
            || ($context['mode'] ?? null) !== config('shop.lalamove.mode')) {
            $this->fail('The Lalamove delivery does not match this order. No delivery was attached or changed.');
        }
        $stops = $data['stops'] ?? null;
        $expected = $context['stops'] ?? null;
        if (! is_array($stops) || ! is_array($expected) || count($stops) !== 2 || count($expected) !== 2) {
            $this->fail('Lalamove did not return the expected pickup and recipient details. No order state was changed.');
        }
        foreach ([0, 1] as $index) {
            foreach (['address', 'name', 'phone'] as $field) {
                $actual = $stops[$index][$field] ?? null;
                $wanted = $expected[$index][$field] ?? null;
                if (! is_string($actual) || ! is_string($wanted) || ! filled($wanted)
                    || $this->identity($actual, $field) === null
                    || $this->identity($actual, $field) !== $this->identity($wanted, $field)) {
                    $this->fail('The Lalamove pickup or recipient details do not match this booking. No order state was changed.');
                }
            }
            foreach (['lat', 'lng'] as $coordinate) {
                $actual = data_get($stops[$index], 'coordinates.'.$coordinate);
                $wanted = data_get($expected[$index], 'coordinates.'.$coordinate);
                if (! is_numeric($actual) || ! is_numeric($wanted) || abs((float) $actual - (float) $wanted) > 0.000001) {
                    $this->fail('The Lalamove pickup or destination coordinates do not match this booking. No order state was changed.');
                }
            }
        }

        return $this->providerStatus($data);
    }

    private function identity(string $value, string $field): ?string
    {
        if ($field === 'phone') {
            return Lalamove::internationalPhone($value);
        }

        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
    }

    private function providerStatus(array $data): string
    {
        $status = $data['status'] ?? null;
        if (! in_array($status, [...self::ACTIVE, ...self::TERMINAL, 'COMPLETED'], true)) {
            $this->fail('Lalamove returned an unknown delivery status. The existing booking remains blocked from retry.');
        }

        return $status;
    }

    private function apply(Order $order, string $id, string $status, array $data): array
    {
        if (($order->provider_completed_at || $order->customer_confirmed_at || $order->status === 'completed') && $status !== 'COMPLETED') {
            $this->fail('The provider status conflicts with the recorded delivery completion. No order state was changed.');
        }
        if ($order->status === 'completed' && $order->customer_confirmed_at) {
            return ['provider_status' => $status, 'previous_status' => 'completed', 'status' => 'completed', 'changed' => false];
        }
        $attempt = DB::table('delivery_attempts')->where('provider_delivery_id', $id)->lockForUpdate()->first();
        if (($attempt && ((int) $attempt->order_id !== $order->id || $attempt->cleared_at !== null || $attempt->provider_mode !== config('shop.lalamove.mode')))
            || Order::where('delivery_id', $id)->whereKeyNot($order->id)->exists()) {
            $this->fail('This delivery ID is already attached to another order or belongs to a closed attempt. No booking was changed.');
        }
        $terminal = in_array($status, self::TERMINAL, true);
        $previousStatus = $order->status;
        $newStatus = $terminal ? 'ready' : 'out_for_delivery';
        $link = $this->trackingLink($data['shareLink'] ?? null) ?: $this->trackingLink($order->tracking_url);
        $updates = ['status' => $newStatus, 'delivery_id' => $terminal ? null : $id, 'tracking_url' => $terminal ? null : $link,
            'delivery_last_status' => $status,
            'provider_completed_at' => $status === 'COMPLETED' ? ($order->provider_completed_at ?? now()) : null];
        $order->fill($updates);
        $changed = $order->isDirty();
        $order->save();
        $attemptData = ['order_id' => $order->id, 'provider_status' => $status, 'provider_mode' => config('shop.lalamove.mode'),
            'checked_at' => now(), 'cleared_at' => $terminal ? now() : null, 'updated_at' => now()];
        if ($attempt) {
            DB::table('delivery_attempts')->where('id', $attempt->id)->update($attemptData);
        } else {
            DB::table('delivery_attempts')->insert($attemptData + ['provider_delivery_id' => $id, 'created_at' => now()]);
        }

        return ['provider_status' => $status, 'previous_status' => $previousStatus, 'status' => $newStatus, 'changed' => $changed];
    }

    private function trackingLink(mixed $link): ?string
    {
        return is_string($link) && filter_var($link, FILTER_VALIDATE_URL) && parse_url($link, PHP_URL_SCHEME) === 'https' ? $link : null;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['delivery' => $message]);
    }
}
