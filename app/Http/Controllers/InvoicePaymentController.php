<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\ActivityRecorder;
use App\Services\InvoicePayments;
use App\Services\InvoiceTerms;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoicePaymentController extends Controller
{
    public function online(Request $request, Order $order, InvoicePayments $payments)
    {
        abort_unless($request->user()->id === $order->user_id || in_array($request->user()->role, ['staff', 'admin'], true), 403);
        $data = $request->validate(['amount' => 'required', 'request_key' => 'required|uuid']);
        try {
            $attempt = $payments->start($order, InvoiceTerms::amount($data['amount']), $request->user(), $data['request_key']);
            if ($attempt->status === 'pending' && $attempt->checkout_url) {
                return redirect()->away($attempt->checkout_url);
            }

            return back()->with('notice', 'This payment request is already saved. Check its recorded status before starting another.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withErrors(['payment' => 'Online checkout could not be confirmed. This invoice and its payment request were saved for reconciliation.']);
        }
    }

    public function cash(Request $request, Order $order, InvoicePayments $payments)
    {
        abort_unless(in_array($request->user()->role, ['staff', 'admin'], true), 403);
        $data = $request->validate(['amount' => 'required', 'reason' => 'required|string|min:10|max:1000', 'payment_received' => 'required|accepted', 'request_key' => 'required|uuid']);
        $payments->recordCash($order, InvoiceTerms::amount($data['amount']), $request->user(), $data['reason'], $data['request_key']);

        return back()->with('notice', 'Cash payment received and recorded on this invoice. Its original deadline is unchanged.');
    }

    public function due(Request $request, Order $order, ActivityRecorder $audit)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['due_at' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($request, $order, $audit, $data) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $due = Carbon::parse($data['due_at'], 'Asia/Manila')->endOfDay();
            if ($locked->outstandingBalance() === 0 || $locked->inventory_released || $due->lte(now())
                || $due->lt($locked->created_at->copy()->addDays(7)) || $due->gt($locked->created_at->copy()->addDays(30)->endOfDay())
                || ($locked->due_at && $due->lte($locked->due_at))) {
                throw ValidationException::withMessages(['due_at' => 'Choose a later agreed deadline within 7–30 days of this invoice’s original creation.']);
            }
            $previous = $locked->due_at?->toIso8601String();
            $locked->update(['due_at' => $due, 'original_due_at' => $locked->original_due_at ?? $due]);
            if (! $audit->record($request->user(), 'invoice.due_changed', $locked, ['previous_due_at' => $previous, 'due_at' => $due->toIso8601String(), 'reason' => $data['reason']])) {
                throw new \RuntimeException('Deadline agreement could not be audited.');
            }
        }, 3);

        return back()->with('notice', 'The agreed deadline was saved with its reason. Payment receipts and the original invoice are unchanged.');
    }

    public function terms(Request $request, ActivityRecorder $audit)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['days' => 'required|integer|between:7,30', 'reason' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($request, $data, $audit) {
            DB::table('shop_settings')->updateOrInsert(['key' => 'partial_payment_due_days'], ['value' => (string) $data['days']]);
            if (! $audit->record($request->user(), 'invoice.terms_changed', null, ['days' => (int) $data['days'], 'reason' => $data['reason']])) {
                throw new \RuntimeException('Payment terms could not be audited.');
            }
        }, 3);

        return back()->with('notice', 'Default installment deadline updated for new invoices. Existing deadlines remain unchanged.');
    }
}
