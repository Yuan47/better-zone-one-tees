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
        return $this->run($request, $order, $delivery, fn () => $recovery->refresh($order));
    }

    public function recover(Request $request, Order $order, DeliveryRecovery $recovery, Lalamove $delivery)
    {
        abort_unless(in_array($request->user()?->role, ['staff', 'admin'], true), 403);
        $data = $request->validate(['delivery_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/D']]);

        return $this->run($request, $order, $delivery, fn () => $recovery->recover($order, $data['delivery_id']));
    }

    private function run(Request $request, Order $order, Lalamove $delivery, callable $operation)
    {
        abort_unless(in_array($request->user()?->role, ['staff', 'admin'], true), 403);
        if (! $delivery->ready()) {
            return back()->withErrors(['delivery' => 'Configure Lalamove credentials and pickup details before checking a delivery.']);
        }
        try {
            $result = $operation();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return back()->withErrors(['delivery' => 'Lalamove could not confirm the delivery. Its existing state was kept; try the status check again later.']);
        }
        if ($result['changed']) {
            ActivityRecorder::mark($request, 'order.delivery_reconciled', $order, ['previous_status' => $result['previous_status'], 'status' => $result['status']]);
        }
        $notice = match (true) {
            $result['status'] === 'ready' => 'Lalamove confirmed that the previous delivery ended. Its history was saved and the order is ready for a new booking.',
            $result['status'] === 'completed' => 'The customer has already confirmed receipt and submitted a review.',
            $result['provider_status'] === 'COMPLETED' => 'Lalamove reported the delivery complete. The order stays With courier until the customer confirms receipt and submits a review.',
            default => 'Lalamove confirmed the existing delivery. It remains attached to this order.',
        };

        return back()->with('notice', $notice);
    }
}
