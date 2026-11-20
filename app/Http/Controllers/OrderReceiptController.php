<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\ActivityRecorder;
use App\Services\DeliveryRecovery;
use App\Services\Lalamove;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderReceiptController extends Controller
{
    public function store(Request $request, Order $order, ActivityRecorder $audit, DeliveryRecovery $recovery, Lalamove $delivery)
    {
        abort_unless($request->user()->role === 'customer' && $request->user()->id === $order->user_id, 403);
        $data = $request->validate(['received' => 'required|accepted', 'rating' => 'required|integer|between:1,5', 'comment' => 'nullable|string|max:2000']);

        // A valid customer submission may arrive before the scheduled courier check.
        // Verify the attached booking without holding the receipt transaction open.
        $current = $order->fresh();
        if ($current->payment_status === 'paid' && $current->payment_id && $current->inventory_committed && ! $current->inventory_released
            && $current->sales_channel !== 'walk_in'
            && $current->delivery_method === 'lalamove' && $current->delivery_id
            && in_array($current->status, ['out_for_delivery', 'delivery_attention'], true)
            && ! $current->provider_completed_at && ! $current->customer_confirmed_at && $delivery->ready()) {
            try {
                $recovery->refresh($current);
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages(['receipt' => 'We could not verify courier completion. No receipt was saved. Check the latest delivery status and try again.']);
            }
        }

        DB::transaction(function () use ($request, $order, $data, $audit) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->customer_confirmed_at && $locked->review()->exists()) {
                return;
            }
            $ready = $locked->delivery_method === 'pickup'
                ? $locked->status === 'ready'
                : ($locked->delivery_method === 'lalamove' && $locked->status === 'out_for_delivery' && $locked->provider_completed_at !== null);
            if ($locked->payment_status !== 'paid' || ! $locked->payment_id || ! $ready) {
                throw ValidationException::withMessages(['receipt' => 'Receipt confirmation is available once your paid order has arrived, or is ready for store pickup.']);
            }
            $previous = $locked->status;
            $locked->review()->create(['user_id' => $request->user()->id, 'rating' => $data['rating'], 'comment' => $data['comment'] ?? '']);
            $locked->update(['status' => 'completed', 'customer_confirmed_at' => now()]);
            if (! $audit->record($request->user(), 'order.received', $locked, ['previous_status' => $previous, 'status' => 'completed', 'reason' => 'Customer confirmed receipt and submitted a rating.'])) {
                throw new \RuntimeException('The receipt confirmation could not be audited.');
            }
        }, 3);

        return back()->with('notice', 'Delivered confirmed. Thank you for your rating. Refund requests are now available on this order.');
    }
}
