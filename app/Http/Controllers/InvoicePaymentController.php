<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\InvoicePayments;
use App\Services\InvoiceTerms;
use Illuminate\Http\Request;
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

        return back()->with('notice', 'The full outstanding balance was received and recorded on this invoice.');
    }
}
