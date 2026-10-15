<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\ActivityRecorder;
use App\Services\PaymentRecovery;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentRecoveryController extends Controller
{
    public function check(Request $request, Order $order, PaymentRecovery $recovery)
    {
        return $this->run($request, $order, fn () => $recovery->check($order));
    }

    public function close(Request $request, Order $order, PaymentRecovery $recovery)
    {
        return $this->run($request, $order, fn () => $recovery->check($order, true));
    }

    public function recover(Request $request, Order $order, PaymentRecovery $recovery)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['checkout_session_id' => ['required', 'string', 'regex:/^cs_[A-Za-z0-9_]{1,120}$/']]);

        return $this->run($request, $order, fn () => $recovery->recover($order, $data['checkout_session_id']));
    }

    private function run(Request $request, Order $order, callable $action)
    {
        abort_unless($request->user()->id === $order->user_id || in_array($request->user()->role, ['staff', 'admin'], true), 403);
        $previous = $order->status;
        try {
            $result = $action();
            ActivityRecorder::mark($request, 'order.payment_reconciled', $order, ['previous_status' => $previous, 'status' => $order->fresh()->status]);
            if ($result === 'cancelled' && (int) $request->session()->get('pending_order') === $order->id) {
                $request->session()->forget('pending_order');
            }

            return back()->with('notice', match ($result) {
                'paid' => 'PayMongo verified this payment. Your order is confirmed.',
                'cancelled' => 'PayMongo verified that this checkout is closed and unpaid. The stock reservation has been released.',
                default => 'PayMongo has not confirmed a payment yet. Your order and stock reservation are saved.',
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withErrors(['payment' => 'PayMongo could not be reached or verified. Your order is saved and stock remains reserved. Try checking again shortly.']);
        }
    }
}
