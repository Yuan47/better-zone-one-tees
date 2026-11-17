<?php

namespace App\Services;

use App\Models\Order;
use App\Rules\ExpectedDeliveryDays;

class DeliveryEstimates
{
    public function current(): string
    {
        return app(StoreKnowledge::class)->info()['expected_delivery_days'];
    }

    public static function periodLabel(string $days): string
    {
        return $days.(! str_contains($days, '-') && (int) $days === 1 ? ' day' : ' days');
    }

    public function description(?array $storeInfo = null): string
    {
        $days = $storeInfo['expected_delivery_days'] ?? $this->current();
        if (! ExpectedDeliveryDays::valid($days)) {
            $days = StoreKnowledge::DEFAULT_EXPECTED_DELIVERY_DAYS;
        }

        return 'Expected delivery: '.self::periodLabel($days).' after confirmed full payment. '
            .'This is a store estimate, not a guaranteed courier arrival time. Timing may vary with packing, courier availability and location. '
            .'Check your order in My Purchases for its saved estimate and latest delivery details. '
            .'Returning from checkout or sending a screenshot does not confirm payment.';
    }

    public function forOrder(Order $order): ?array
    {
        if ($order->sales_channel === 'walk_in' || $order->delivery_method !== 'lalamove'
            || $order->status === 'cancelled' || $order->inventory_released || $order->deliveryConfirmed()) {
            return null;
        }

        $saved = $order->expected_delivery_days;

        return ['days' => ExpectedDeliveryDays::valid($saved) ? $saved : $this->current(),
            'legacy' => ! ExpectedDeliveryDays::valid($saved), 'pending' => $order->payment_status !== 'paid'];
    }
}
