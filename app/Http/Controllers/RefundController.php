<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Services\ActivityRecorder;
use App\Services\RefundVerification;
use App\Services\ReturnInspections;
use App\Support\WorkspaceTableSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Refund::STATUSES)], 'q' => 'nullable|string|max:100']);
        $refunds = Refund::with('order.user', 'paymentAttempt')->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->whereHas('order', fn ($query) => $query->where('number', 'like', '%'.$search.'%')));
        $cash = "EXISTS (SELECT 1 FROM payment_attempts WHERE payment_attempts.id = refunds.payment_attempt_id AND payment_attempts.method = 'cash')";
        $label = "CASE refunds.status WHEN 'requested' THEN 'Awaiting review'
            WHEN 'approved' THEN CASE WHEN $cash THEN 'Approved · awaiting cash return' ELSE 'Approved · awaiting provider refund' END
            WHEN 'rejected' THEN 'Request declined' WHEN 'pending' THEN 'PayMongo processing' WHEN 'processing' THEN 'PayMongo processing'
            WHEN 'succeeded' THEN CASE WHEN $cash THEN 'Recorded cash refund' ELSE 'PayMongo confirmed refund' END
            WHEN 'failed' THEN 'Provider refund failed' ELSE 'Needs review' END";
        $refunds = WorkspaceTableSort::apply($refunds, $request, [
            'request' => 'refunds.id', 'order' => '(SELECT orders.number FROM orders WHERE orders.id = refunds.order_id)',
            'requested' => 'refunds.requested_amount', 'amount' => 'refunds.amount', 'status' => $label,
        ], fn ($query) => $query->orderByRaw("CASE WHEN status = 'requested' THEN 0 WHEN status = 'approved' THEN 1 WHEN status IN ('pending', 'processing') THEN 2 ELSE 3 END")
            ->latest())->paginate(20)->withQueryString();

        return view('admin.refunds', ['refunds' => $refunds, 'returnPolicy' => app(ReturnInspections::class)->policy()]);
    }

    public function show(Refund $refund)
    {
        $refund->load('order.user', 'order.items', 'order.returnInspections', 'paymentAttempt', 'cashRecordedBy', 'returnInspection.inspector');
        $paymentOptions = PaymentAttempt::where('order_id', $refund->order_id)->where('status', 'paid')->orderBy('received_at')->orderBy('id')->get()
            ->map(fn ($payment) => ['payment' => $payment, 'remaining' => min(Refund::remainingForOrder($refund->order, $refund->id), Refund::remainingForPayment($refund->order, $payment, $refund->id))]);

        return view('admin.refund', compact('refund', 'paymentOptions'));
    }

    public function store(Request $request, Order $order)
    {
        abort_unless($request->user()->id === $order->user_id && $request->user()->role === 'customer', 403);
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000', 'customer_photo' => ReturnInspections::photoRules()]);
        $photo = app(ReturnInspections::class)->storePhoto($request->file('customer_photo'));
        try {
            $refund = DB::transaction(function () use ($request, $order, $data, $photo) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->payment_status !== 'paid' || (! $locked->payment_id && ! PaymentAttempt::where('order_id', $locked->id)->where('status', 'paid')->exists())) {
                    $this->fail('Refund requests are available only for verified paid orders.');
                }
                if (! $locked->deliveryConfirmed()) {
                    $this->fail('Refund requests are available only after confirmed delivery or a completed walk-in handover.');
                }
                if ($locked->refunds()->whereIn('status', ['requested', 'approved', 'pending', 'processing'])->exists()) {
                    $this->fail('This order already has a refund request under review or processing.');
                }
                $remaining = Refund::remainingForOrder($locked);
                if ($remaining < 100) {
                    $this->fail('This payment has already been refunded.');
                }

                return $locked->refunds()->create(['requested_by' => $request->user()->id, 'reason' => $data['reason'], 'requested_amount' => $remaining,
                    'customer_photo_path' => $photo] + app(ReturnInspections::class)->snapshot($locked));
            }, 3);
        } catch (\Throwable $exception) {
            if ($photo) {
                Storage::disk('local')->delete($photo);
            }
            throw $exception;
        }
        ActivityRecorder::mark($request, 'refund.requested', $refund);

        return back()->with('notice', 'Your refund request was sent for store review. This does not yet confirm a refund.');
    }

    public function review(Request $request, Refund $refund)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'decision_note' => 'required|string|min:5|max:2000',
            'approved_amount' => 'required_if:decision,approved|nullable|regex:/^\d+(\.\d{1,2})?$/|numeric|min:1|max:100000000',
            'payment_attempt_id' => 'nullable|integer|min:1']);
        DB::transaction(function () use ($request, $refund, $data) {
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_status !== 'paid' || (! $order->payment_id && ! PaymentAttempt::where('order_id', $order->id)->where('status', 'paid')->exists())) {
                $this->fail('Only a verified paid order can be reviewed for a refund.');
            }
            if (! $order->deliveryConfirmed()) {
                $this->fail('Refunds can be reviewed only after confirmed delivery or a completed walk-in handover.');
            }
            if ($locked->status !== 'requested') {
                $this->fail('This request has already been reviewed.');
            }
            $amount = $data['decision'] === 'approved' ? (int) round((float) $data['approved_amount'] * 100) : null;
            $remaining = Refund::remainingForOrder($order, $locked->id);
            if ($amount !== null && ($amount > $remaining || $amount > $locked->requested_amount)) {
                $this->fail('The approved refund cannot exceed the request or remaining paid amount.');
            }
            $updates = ['status' => $data['decision'], 'approved_amount' => $amount, 'decision_note' => $data['decision_note'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()];
            if ($amount !== null) {
                $updates += app(ReturnInspections::class)->allocation($locked, $amount);
                $sourceId = $data['payment_attempt_id'] ?? $locked->payment_attempt_id;
                if ($sourceId !== null) {
                    $source = PaymentAttempt::where('order_id', $order->id)->whereKey($sourceId)->where('status', 'paid')->whereIn('method', ['cash', 'paymongo'])->lockForUpdate()->first();
                    if (! $source || $amount > Refund::remainingForPayment($order, $source, $locked->id)) {
                        $this->fail('Choose a paid receipt from this invoice whose remaining amount covers the approved refund.');
                    }
                    $updates['payment_attempt_id'] = $source->id;
                } elseif (PaymentAttempt::where('order_id', $order->id)->where('status', 'paid')->exists()) {
                    $this->fail('Choose the payment receipt for this refund.');
                }
            }
            $locked->update($updates);
        }, 3);
        ActivityRecorder::mark($request, 'refund.reviewed', $refund);

        if ($data['decision'] === 'approved') {
            return back()->with('notice', $refund->fresh('paymentAttempt')->isCash()
                ? 'Cash return approved. Record it after the money has been returned.'
                : 'Refund approved. Complete it in PayMongo, then verify its reference against the selected receipt.');
        }

        return back()->with('notice', 'Refund request declined. The customer can view the reason on the order.');
    }

    public function recover(Request $request, Refund $refund, RefundVerification $verification)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['provider_refund_id' => ['required', 'string', 'regex:/^ref_[A-Za-z0-9_]{1,120}$/D'], 'payment_attempt_id' => 'nullable|integer|min:1']);

        return $this->verify($request, $refund, $verification, $data['provider_refund_id'], isset($data['payment_attempt_id']) ? (int) $data['payment_attempt_id'] : null);
    }

    public function check(Request $request, Refund $refund, RefundVerification $verification)
    {
        return $this->verify($request, $refund, $verification);
    }

    public function cash(Request $request, Refund $refund, ActivityRecorder $recorder)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['payment_attempt_id' => 'required|integer|min:1', 'cash_reason' => 'required|string|min:10|max:2000']);
        try {
            DB::transaction(function () use ($request, $refund, $data, $recorder) {
                $order = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
                $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
                if ($order->payment_status !== 'paid' || ! $order->deliveryConfirmed()) {
                    $this->fail('Cash refunds require a fully paid invoice and confirmed delivery or a completed walk-in handover.');
                }
                if ($locked->status !== 'approved' || $locked->provider_refund_id !== null) {
                    $this->fail('Only an approved refund without a provider reference can be recorded as returned cash.');
                }
                if ($locked->payment_attempt_id !== null && (int) $locked->payment_attempt_id !== (int) $data['payment_attempt_id']) {
                    $this->fail('The selected receipt differs from the approved refund source.');
                }
                $source = PaymentAttempt::whereKey($data['payment_attempt_id'])->where('order_id', $order->id)->where('status', 'paid')->where('method', 'cash')->lockForUpdate()->first();
                $amount = (int) $locked->approved_amount;
                if (! $source || ! $source->received_at || $amount < 100 || $amount > $locked->requested_amount
                    || $amount > Refund::remainingForPayment($order, $source, $locked->id) || $amount > Refund::remainingForOrder($order, $locked->id)) {
                    $this->fail('The cash return exceeds the approved request or remaining amount on its paid cash receipt and invoice.');
                }
                $allocation = app(ReturnInspections::class)->allocation($locked, $amount);
                $locked->update(['payment_attempt_id' => $source->id, 'amount' => $amount, 'status' => 'succeeded', 'confirmed_at' => now(),
                    'cash_recorded_by' => $request->user()->id, 'cash_reason' => $data['cash_reason']] + $allocation);
                if (! $recorder->record($request->user(), 'refund.cash_recorded', $locked, ['reason' => $data['cash_reason'], 'amount' => $amount, 'method' => 'cash'])) {
                    throw new \RuntimeException('Cash refund audit could not be recorded.');
                }
            }, 3);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return back()->withErrors(['refund' => 'The cash return record could not be saved. No confirmation was added. Try saving the same return record again.']);
        }

        return back()->with('notice', 'The completed cash return was recorded against its payment receipt.');
    }

    private function verify(Request $request, Refund $refund, RefundVerification $verification, ?string $id = null, ?int $paymentAttemptId = null)
    {
        try {
            $result = $verification->check($refund, $id, $paymentAttemptId);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withErrors(['refund' => 'PayMongo could not verify this refund. The saved status was kept; try again shortly.']);
        }
        ActivityRecorder::mark($request, 'refund.verified', $refund);

        return back()->with('notice', $result->label().'.');
    }

    public function inspect(Request $request, Refund $refund, ReturnInspections $inspections)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['existing_inspection_id' => 'nullable|integer|min:1',
            'items' => 'nullable|array|max:100', 'items.*.order_item_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:0|max:10000000', 'items.*.condition' => ['required', Rule::in(['good', 'damaged', 'wrong_item'])],
            'include_delivery' => 'nullable|boolean', 'inspection_note' => 'nullable|string|min:10|max:2000',
            'inspection_photo' => ReturnInspections::photoRules(), 'photo_exception_reason' => 'nullable|string|min:10|max:2000',
            'late_exception_reason' => 'nullable|string|min:10|max:2000']);
        $photo = $inspections->storePhoto($request->file('inspection_photo'));
        try {
            $inspections->record($refund, $request->user(), $data, $photo);
        } catch (\Throwable $exception) {
            if ($photo) {
                Storage::disk('local')->delete($photo);
            }
            throw $exception;
        }

        return back()->with('notice', 'The physical return inspection is saved. It can be reused for further receipt refunds on this same returned clothing.');
    }

    public function evidence(Request $request, Refund $refund, string $kind)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'staff'], true), 403);
        $path = match ($kind) {
            'customer' => $refund->customer_photo_path,
            'inspection' => $refund->returnInspection?->photo_path,
            default => null,
        };
        abort_unless($path && str_starts_with($path, 'refund-evidence/') && Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), [
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
        // BinaryFileResponse sets public caching during construction.
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function policy(Request $request, ActivityRecorder $recorder)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['days' => 'required|integer|between:1,30', 'delivery_eligible' => 'required|boolean',
            'reason' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($request, $data, $recorder) {
            DB::table('shop_settings')->updateOrInsert(['key' => 'return_policy'], ['value' => json_encode([
                'days' => (int) $data['days'], 'delivery_eligible' => (bool) $data['delivery_eligible']])]);
            if (! $recorder->record($request->user(), 'refund.policy_updated', null, ['reason' => $data['reason']])) {
                throw new \RuntimeException('Return policy audit could not be recorded.');
            }
        });

        return back()->with('notice', 'Return policy saved for future requests. Existing requests and recorded inspections keep their original policy.');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['refund' => $message]);
    }
}
