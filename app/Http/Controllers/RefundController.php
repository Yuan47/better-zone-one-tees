<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Refund;
use App\Services\ActivityRecorder;
use App\Services\RefundVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Refund::STATUSES)], 'q' => 'nullable|string|max:100']);
        $refunds = Refund::with('order.user')->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->whereHas('order', fn ($query) => $query->where('number', 'like', '%'.$search.'%')))
            ->orderByRaw("CASE WHEN status = 'requested' THEN 0 WHEN status = 'approved' THEN 1 WHEN status IN ('pending', 'processing') THEN 2 ELSE 3 END")
            ->latest()->paginate(20)->withQueryString();

        return view('admin.refunds', compact('refunds'));
    }

    public function show(Refund $refund)
    {
        $refund->load('order.user');

        return view('admin.refund', compact('refund'));
    }

    public function store(Request $request, Order $order)
    {
        abort_unless($request->user()->id === $order->user_id && $request->user()->role === 'customer', 403);
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000']);
        $refund = DB::transaction(function () use ($request, $order, $data) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_status !== 'paid' || ! $locked->payment_id) {
                $this->fail('Refund requests are available only for verified paid orders.');
            }
            if ($locked->status !== 'completed' || ! $locked->customer_confirmed_at) {
                $this->fail('Refund requests are available only after you confirm Delivered and submit your review.');
            }
            if ($locked->refunds()->whereIn('status', ['requested', 'approved', 'pending', 'processing'])->exists()) {
                $this->fail('This order already has a refund request under review or processing.');
            }
            $remaining = $locked->total - (int) $locked->refunds()->where('status', 'succeeded')->sum('amount');
            if ($remaining < 100) {
                $this->fail('This payment has already been refunded.');
            }

            return $locked->refunds()->create(['requested_by' => $request->user()->id, 'reason' => $data['reason'], 'requested_amount' => $remaining]);
        }, 3);
        ActivityRecorder::mark($request, 'refund.requested', $refund);

        return back()->with('notice', 'Your refund request was sent for store review. This does not yet confirm a refund.');
    }

    public function review(Request $request, Refund $refund)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'decision_note' => 'required|string|min:5|max:2000',
            'approved_amount' => 'required_if:decision,approved|nullable|regex:/^\d+(\.\d{1,2})?$/|numeric|min:1|max:100000000']);
        DB::transaction(function () use ($request, $refund, $data) {
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_status !== 'paid' || ! $order->payment_id) {
                $this->fail('Only a verified paid order can be reviewed for a refund.');
            }
            if ($order->status !== 'completed' || ! $order->customer_confirmed_at) {
                $this->fail('Refunds can be reviewed only after the customer confirms delivery and reviews the order.');
            }
            if ($locked->status !== 'requested') {
                $this->fail('This request has already been reviewed.');
            }
            $amount = $data['decision'] === 'approved' ? (int) round((float) $data['approved_amount'] * 100) : null;
            $remaining = $order->total - (int) $order->refunds()->where('status', 'succeeded')->sum('amount');
            if ($amount !== null && ($amount > $remaining || $amount > $locked->requested_amount)) {
                $this->fail('The approved refund cannot exceed the request or remaining paid amount.');
            }
            $locked->update(['status' => $data['decision'], 'approved_amount' => $amount, 'decision_note' => $data['decision_note'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        }, 3);
        ActivityRecorder::mark($request, 'refund.reviewed', $refund);

        return back()->with('notice', $data['decision'] === 'approved' ? 'Request approved for processing. Complete the refund in PayMongo, then verify its reference here.' : 'Refund request declined. The customer can view the reason on the order.');
    }

    public function recover(Request $request, Refund $refund, RefundVerification $verification)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['provider_refund_id' => ['required', 'string', 'regex:/^ref_[A-Za-z0-9_]{1,120}$/D']]);

        return $this->verify($request, $refund, $verification, $data['provider_refund_id']);
    }

    public function check(Request $request, Refund $refund, RefundVerification $verification)
    {
        return $this->verify($request, $refund, $verification);
    }

    private function verify(Request $request, Refund $refund, RefundVerification $verification, ?string $id = null)
    {
        try {
            $result = $verification->check($refund, $id);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withErrors(['refund' => 'PayMongo could not verify this refund. The saved status was kept; try again shortly.']);
        }
        ActivityRecorder::mark($request, 'refund.verified', $refund);

        return back()->with('notice', $result->label().'.');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['refund' => $message]);
    }
}
