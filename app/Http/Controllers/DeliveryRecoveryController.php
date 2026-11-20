<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\ActivityRecorder;
use App\Services\DeliveryRecovery;
use App\Services\Lalamove;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeliveryRecoveryController extends Controller
{
    public function refresh(Request $request, Order $order, DeliveryRecovery $recovery, Lalamove $delivery)
    {
        return $this->run($request, $order, $delivery, fn () => $recovery->refresh($order), $request->routeIs('orders.delivery.refresh'));
    }

    public function recover(Request $request, Order $order, DeliveryRecovery $recovery, Lalamove $delivery)
    {
        abort_unless(in_array($request->user()?->role, ['staff', 'admin'], true), 403);
        $data = $request->validate(['delivery_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/D']]);

        return $this->run($request, $order, $delivery, fn () => $recovery->recover($order, $data['delivery_id']));
    }

    private function run(Request $request, Order $order, Lalamove $delivery, callable $operation, bool $customerRefresh = false)
    {
        $return = fn () => $customerRefresh
            ? redirect()->route('orders.show', $order)->withFragment('order-receipt')
            : back();
        if ($customerRefresh) {
            abort_unless($request->user()?->role === 'customer' && $request->user()->id === $order->user_id, 403);
            abort_unless($order->payment_status === 'paid' && $order->payment_id && $order->inventory_committed
                && ! $order->inventory_released && $order->sales_channel !== 'walk_in'
                && $order->delivery_method === 'lalamove' && $order->delivery_id && ! $order->customer_confirmed_at
                && in_array($order->status, ['out_for_delivery', 'delivery_attention'], true), 403);
            if ($order->provider_completed_at && $order->status === 'out_for_delivery') {
                return $return()->with('notice', 'Courier completed · confirm receipt. Choose your stars and confirm only after receiving and checking your clothing.');
            }
        } else {
            abort_unless(in_array($request->user()?->role, ['staff', 'admin'], true), 403);
        }
        if (! $delivery->ready()) {
            return $return()->withErrors(['delivery' => $customerRefresh
                ? 'Delivery status is temporarily unavailable. Try again later or contact the store.'
                : 'Configure Lalamove credentials and pickup details before checking a delivery.']);
        }
        try {
            $result = $operation();
        } catch (ValidationException $exception) {
            if ($customerRefresh) {
                return $return()->withErrors(['delivery' => 'Lalamove could not verify this delivery. Its saved status was kept. Try again later or ask store staff to check the original order.']);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            return $return()->withErrors(['delivery' => 'Lalamove could not confirm the delivery. Its existing state was kept; try the status check again later.']);
        }
        if ($result['changed']) {
            ActivityRecorder::mark($request, 'order.delivery_reconciled', $order, ['previous_status' => $result['previous_status'], 'status' => $result['status']]);
        }
        $notice = $customerRefresh ? match (true) {
            $result['provider_status'] === 'COMPLETED' => 'Courier completed · confirm receipt. Choose your stars and confirm only after receiving and checking your clothing.',
            $result['status'] === 'ready' => 'The previous courier booking ended. The store will check the order and arrange its next delivery.',
            default => 'Lalamove still shows delivery in progress. Receipt confirmation opens after courier completion is verified.',
        } : match (true) {
            $result['status'] === 'ready' => 'Lalamove confirmed that the previous delivery ended. Its history was saved and the order is ready for a new booking.',
            $result['status'] === 'completed' => 'The customer has already confirmed receipt and submitted a rating.',
            $result['provider_status'] === 'COMPLETED' => 'Lalamove reported the delivery complete. The order stays To Ship until the customer confirms receipt and chooses a rating. A written review is optional.',
            default => 'Lalamove confirmed the existing delivery. It remains attached to this order.',
        };

        return $return()->with('notice', $notice);
    }
}
