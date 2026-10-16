<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Refund;
use App\Services\InvoicePayments;
use App\Services\InvoiceTerms;
use App\Services\PaymentRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuestInvoiceController extends Controller
{
    private function guard(Order $order): void
    {
        abort_unless(! $order->user_id && $order->sales_channel === 'walk_in', 404);
    }

    public function show(Request $request, Order $order)
    {
        $this->guard($order);
        $order->load('items', 'payments', 'refunds');

        return response()->view('guest-invoice', compact('order'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function pay(Request $request, Order $order, InvoicePayments $payments)
    {
        $this->guard($order);
        $data = $request->validate(['amount' => 'required', 'request_key' => 'required|uuid']);
        try {
            $attempt = $payments->start($order, InvoiceTerms::amount($data['amount']), null, $data['request_key'], true);

            return $attempt->status === 'pending' && $attempt->checkout_url ? redirect()->away($attempt->checkout_url) : back()->with('notice', 'The payment request is saved. Check its status before starting another.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withErrors(['payment' => 'Online checkout could not be confirmed. Keep this invoice for store reconciliation.']);
        }
    }

    public function check(Request $request, Order $order, PaymentRecovery $payments)
    {
        $this->guard($order);
        try {
            $result = $payments->check($order);

            return back()->with('notice', $result === 'paid' ? 'Payment received. This walk-in invoice is fully paid and Delivered.' : ($result === 'partially_paid' ? 'Your partial payment is recorded. The remaining balance stays on this invoice.' : 'Payment has not been confirmed yet.'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withErrors(['payment' => 'Payment status could not be verified. Your saved invoice was kept.']);
        }
    }

    public function refund(Request $request, Order $order)
    {
        $this->guard($order);
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($order, $data) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_status !== 'paid' || ! $locked->deliveryConfirmed() || $locked->refunds()->whereIn('status', ['requested', 'approved', 'pending', 'processing'])->exists()
                || ($remaining = Refund::remainingForOrder($locked)) < 100) {
                throw ValidationException::withMessages(['refund' => 'A request is available only after full payment and walk-in delivery, with no other refund processing.']);
            }
            $refund = $locked->refunds()->create(['requested_by' => null, 'reason' => $data['reason'], 'requested_amount' => $remaining]);
            ActivityLog::create(['actor_id' => null, 'actor_name' => 'Guest invoice holder', 'actor_role' => 'customer', 'action' => 'refund.requested', 'target_type' => 'refund', 'target_id' => $refund->id, 'metadata' => [], 'occurred_at' => now()]);
        }, 3);

        return back()->with('notice', 'Your refund request was sent for store review. No refund has been confirmed yet.');
    }
}
