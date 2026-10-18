<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['requested', 'approved', 'rejected', 'pending', 'processing', 'succeeded', 'failed'];

    protected function casts(): array
    {
        return ['requested_amount' => 'integer', 'approved_amount' => 'integer', 'amount' => 'integer', 'payment_attempt_id' => 'integer', 'cash_recorded_by' => 'integer',
            'provider_updated_at' => 'integer', 'reviewed_at' => 'datetime', 'confirmed_at' => 'datetime',
            'policy_days' => 'integer', 'policy_delivery_eligible' => 'boolean', 'policy_late' => 'boolean',
            'policy_delivered_at' => 'datetime', 'policy_deadline_at' => 'datetime',
            'clothing_amount' => 'integer', 'delivery_amount' => 'integer'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentAttempt()
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function returnInspection()
    {
        return $this->belongsTo(ReturnInspection::class);
    }

    public function cashRecordedBy()
    {
        return $this->belongsTo(User::class, 'cash_recorded_by');
    }

    public function isCash(): bool
    {
        return $this->paymentAttempt?->method === 'cash';
    }

    public static function receivedForOrder(Order $order): int
    {
        $receipts = PaymentAttempt::where('order_id', $order->id)->where('status', 'paid')
            ->selectRaw('COUNT(*) as receipt_count, COALESCE(SUM(amount), 0) as received')->first();
        $received = (int) $receipts->received;
        if ((int) $receipts->receipt_count === 0) {
            $received = (int) $order->amount_paid;
            if ($received === 0 && $order->payment_status === 'paid') {
                $received = $order->total;
            }
        }

        return max(0, min((int) $order->total, $received));
    }

    public static function remainingForOrder(Order $order, ?int $exceptRefundId = null): int
    {
        return max(0, self::receivedForOrder($order) - (int) self::allocated($order, $exceptRefundId)->sum('amount'));
    }

    public static function remainingForPayment(Order $order, PaymentAttempt $payment, ?int $exceptRefundId = null): int
    {
        if ((int) $payment->order_id !== (int) $order->id || $payment->status !== 'paid' || ! in_array($payment->method, ['cash', 'paymongo'], true)) {
            return 0;
        }
        $allocated = self::allocated($order, $exceptRefundId)->where(function ($query) use ($order, $payment) {
            $query->where('payment_attempt_id', $payment->id);
            if ($payment->method === 'paymongo' && $payment->payment_id === $order->payment_id) {
                // Pre-ledger refunds retain their compatibility payment reference.
                $query->orWhereNull('payment_attempt_id');
            }
        });

        return max(0, (int) $payment->amount - (int) $allocated->sum('amount'));
    }

    private static function allocated(Order $order, ?int $exceptRefundId): Builder
    {
        return self::where('order_id', $order->id)->when($exceptRefundId, fn ($query, $id) => $query->whereKeyNot($id))
            ->where('amount', '>', 0)->where(fn ($query) => $query->where('status', 'succeeded')
            ->orWhere(fn ($query) => $query->whereIn('status', ['pending', 'processing'])->whereNotNull('provider_refund_id')));
    }

    public function label(): string
    {
        return match ($this->status) {
            'requested' => 'Awaiting review', 'approved' => $this->isCash() ? 'Approved · awaiting cash return' : 'Approved · awaiting provider refund',
            'rejected' => 'Request declined', 'pending', 'processing' => 'PayMongo processing',
            'succeeded' => $this->isCash() ? 'Recorded cash refund' : 'PayMongo confirmed refund', 'failed' => 'Provider refund failed', default => 'Needs review',
        };
    }
}
