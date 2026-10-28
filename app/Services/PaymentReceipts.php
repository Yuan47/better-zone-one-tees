<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceiptNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentReceipts
{
    public function cutoff(): ?CarbonImmutable
    {
        $value = config('services.brevo.payment_receipts_from');
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);

            return $date && $date->format('Y-m-d\TH:i:sP') === $value ? $date->setTimezone('Asia/Manila') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function ready(): bool
    {
        return config('services.brevo.payment_receipts_enabled') === true && $this->cutoff() !== null
            && in_array(config('shop.paymongo.mode'), ['test', 'live'], true) && app(BrevoTransactions::class)->configured();
    }

    /** Discover the committed payment ledger outside every financial/webhook transaction. */
    public function run(?int $paymentId = null, bool $dryRun = false, ?string $reason = null, bool $retryRejected = false): array
    {
        if (($paymentId !== null && ($paymentId < 1 || ! is_string($reason) || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 1000))
            || ($paymentId === null && ($reason !== null || $retryRejected))) {
            throw ValidationException::withMessages(['receipt' => 'A historical receipt or rejected-email retry requires one payment ID and a 10–1,000 character reason.']);
        }
        $counts = ['enabled' => config('services.brevo.payment_receipts_enabled') === true, 'configured' => $this->ready(),
            'dry_run' => $dryRun, 'eligible' => 0, 'accepted' => 0, 'failed' => 0, 'unknown' => 0, 'skipped' => 0, 'review_required' => 0];
        if (! $this->ready()) {
            return $counts;
        }
        $query = PaymentAttempt::where('method', 'paymongo')->where('status', 'paid')->where('legacy', false)->whereNotNull('received_at');
        if ($paymentId !== null) {
            $query->whereKey($paymentId);
        } else {
            $query->where(fn ($receipts) => $receipts
                ->where(fn ($new) => $new->where('received_at', '>=', $this->cutoff())->whereDoesntHave('receiptNotification'))
                ->orWhereHas('receiptNotification', fn ($queued) => $queued->where('status', 'queued')));
        }
        $query->orderBy('id')->chunkById(100, function ($payments) use ($paymentId, $dryRun, $reason, $retryRejected, &$counts) {
            foreach ($payments as $payment) {
                $existing = $payment->receiptNotification()->first();
                $sendable = $retryRejected ? $existing?->status === 'failed' : ($existing === null || $existing->status === 'queued');
                if (! $sendable || ! $this->eligible($payment, $payment->order()->with('user')->first())) {
                    $counts['skipped']++;

                    continue;
                }
                if ($dryRun) {
                    $counts['eligible']++;

                    continue;
                }
                $notification = $retryRejected ? $existing : $this->enqueue($payment, $paymentId === null ? null : trim($reason));
                if (! $notification) {
                    $counts['skipped']++;

                    continue;
                }
                $counts['eligible']++;
                $result = $this->deliver($notification->id, $retryRejected ? trim($reason) : null);
                $counts[array_key_exists($result, $counts) ? $result : 'skipped']++;
            }
        });
        $counts['review_required'] = PaymentReceiptNotification::whereIn('status', ['failed', 'unknown', 'claimed'])->count();

        return $counts;
    }

    private function enqueue(PaymentAttempt $payment, ?string $backfillReason): ?PaymentReceiptNotification
    {
        return DB::transaction(function () use ($payment, $backfillReason) {
            $order = Order::with('user')->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentAttempt::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $this->eligible($locked, $order)) {
                return null;
            }
            if ($existing = PaymentReceiptNotification::where('payment_attempt_id', $locked->id)->lockForUpdate()->first()) {
                return $existing;
            }
            $received = (int) $order->payments()->where('status', 'paid')->where('id', '<=', $locked->id)->sum('amount');

            return PaymentReceiptNotification::create(['payment_attempt_id' => $locked->id, 'order_id' => $order->id,
                'claim_key' => (string) Str::uuid(), 'status' => 'queued', 'receipt_reference' => $locked->reference,
                'payment_id' => $locked->payment_id, 'payment_mode' => config('shop.paymongo.mode'), 'amount' => $locked->amount, 'received_at' => $locked->received_at,
                'balance_after' => max(0, $order->total - $received), 'backfill_reason' => $backfillReason]);
        }, 3);
    }

    /** The claim commits before the one HTTP request; payment/order locks are never held during email sending. */
    public function deliver(int $notificationId, ?string $retryReason = null): string
    {
        if (! $this->ready()) {
            return 'skipped';
        }
        try {
            $claim = DB::transaction(function () use ($notificationId, $retryReason) {
                $notification = PaymentReceiptNotification::whereKey($notificationId)->lockForUpdate()->firstOrFail();
                $allowed = $retryReason === null ? ['queued'] : ['failed'];
                if (! in_array($notification->status, $allowed, true)
                    || ($retryReason !== null && (mb_strlen(trim($retryReason)) < 10 || mb_strlen($retryReason) > 1000))) {
                    return null;
                }
                $payment = PaymentAttempt::with('order.user')->findOrFail($notification->payment_attempt_id);
                $order = $payment->order;
                $recipient = $this->recipient($order);
                if (! $this->eligible($payment, $order) || $recipient === null || ! $this->sameReceipt($notification, $payment)) {
                    $notification->update(['status' => 'skipped', 'failure_reason' => 'receipt_or_recipient_changed']);

                    return null;
                }
                $name = $order->user?->name ?? $order->recipient;
                $invoiceUrl = $order->user_id ? route('orders.show', $order) : URL::temporarySignedRoute('guest.orders.show', now()->addDays(30), ['order' => $order->id]);
                $isTest = $notification->payment_mode === 'test';
                try {
                    $html = view('mail.payment-receipt', compact('notification', 'order', 'name', 'invoiceUrl', 'isTest'))->render();
                } catch (\Throwable) {
                    $notification->update(['status' => 'failed', 'failure_reason' => 'email_render_failed']);

                    return null;
                }
                $notification->update(['status' => 'claimed', 'recipient' => $recipient, 'attempts' => $notification->attempts + 1,
                    'retry_reason' => $retryReason, 'attempted_at' => now(), 'provider_status' => null, 'failure_reason' => null]);

                return ['id' => $notification->id, 'recipient' => $recipient, 'payload' => [
                    'to' => [['email' => $recipient, 'name' => BrevoTransactions::recipientName($name)]],
                    'subject' => ($isTest ? 'TEST — NO REAL MONEY — ' : '').'Zone One Tees payment receipt · PHP '.number_format($notification->amount / 100, 2).' · '.$order->number,
                    'htmlContent' => $html,
                    'textContent' => ($isTest ? 'TEST PAYMENT ONLY. No real money moved. ' : '').'Hello '.$name.', Zone One Tees recorded your verified online payment of PHP '.number_format($notification->amount / 100, 2)
                        .' for invoice '.$order->number.'. Store receipt reference: '.$notification->receipt_reference.'. Received: '.$notification->received_at->format('F j, Y g:i A').' (Asia/Manila).'
                        .' Original invoice total: PHP '.number_format($order->total / 100, 2).'. Invoice balance after this payment: PHP '.number_format($notification->balance_after / 100, 2).'. This is a store-issued payment acknowledgment, separate from PayMongo email and any tax invoice.'
                        .' Later payments and refunds appear on your invoice: '.$invoiceUrl,
                    'headers' => ['IdempotencyKey' => $notification->claim_key], 'tags' => ['store-payment-receipt'],
                ]];
            }, 3);
            if (! $claim) {
                return 'skipped';
            }
            // Recheck ownership immediately before transport. The immutable historical receipt is the email's authority.
            $current = PaymentReceiptNotification::findOrFail($claim['id']);
            $payment = PaymentAttempt::with('order.user')->findOrFail($current->payment_attempt_id);
            if (! $this->ready() || $current->status !== 'claimed' || ! $this->eligible($payment, $payment->order)
                || ! $this->sameReceipt($current, $payment) || $this->recipient($payment->order) !== $claim['recipient']) {
                PaymentReceiptNotification::whereKey($current->id)->where('status', 'claimed')->update(['status' => 'skipped', 'failure_reason' => 'receipt_or_recipient_changed_before_send']);

                return 'skipped';
            }
            $result = app(BrevoTransactions::class)->send($claim['payload']);

            return DB::transaction(function () use ($claim, $result) {
                $notification = PaymentReceiptNotification::whereKey($claim['id'])->lockForUpdate()->firstOrFail();
                if ($notification->status !== 'claimed') {
                    return 'unknown';
                }
                $rejected = $result['status'] === 'failed' && in_array($result['provider_status'], [400, 401, 402, 403, 404, 405, 406, 410, 413, 415, 422, 429], true);
                $status = $result['status'] === 'sent' ? 'accepted' : ($rejected ? 'failed' : 'unknown');
                if ($status === 'accepted' && PaymentReceiptNotification::where('provider_message_id', $result['message_id'])->whereKeyNot($notification->id)->exists()) {
                    $status = 'unknown';
                }
                $notification->update(['status' => $status, 'provider_status' => $result['provider_status'],
                    'last_rejected_status' => $rejected ? $result['provider_status'] : $notification->last_rejected_status,
                    'provider_message_id' => $status === 'accepted' ? $result['message_id'] : null, 'accepted_at' => $status === 'accepted' ? now() : null,
                    'failure_reason' => match ($status) {
                        'accepted' => null, 'failed' => 'provider_rejected_request', default => 'provider_acceptance_not_verified'
                    }]);

                return $status;
            }, 1);
        } catch (\Throwable) {
            // A committed claim survives interruption or failed persistence after HTTP. It is never automatically retried.
            return 'unknown';
        }
    }

    private function eligible(PaymentAttempt $payment, ?Order $order): bool
    {
        return $order !== null && $payment->method === 'paymongo' && $payment->status === 'paid' && ! $payment->legacy
            && $payment->amount >= 100 && $payment->received_at !== null && Str::isUuid($payment->reference)
            && is_string($payment->payment_id) && preg_match('/^pay_[A-Za-z0-9_]{1,120}$/D', $payment->payment_id) === 1
            && $payment->order_id === $order->id && in_array($order->payment_status, ['partially_paid', 'paid'], true)
            && $order->amount_paid >= $payment->amount && $order->total >= $order->amount_paid && $this->recipient($order) !== null;
    }

    private function sameReceipt(PaymentReceiptNotification $notification, PaymentAttempt $payment): bool
    {
        return $notification->order_id === $payment->order_id && $notification->receipt_reference === $payment->reference
            && $notification->payment_id === $payment->payment_id && $notification->amount === $payment->amount
            && in_array($notification->payment_mode, ['test', 'live'], true)
            && $notification->received_at->equalTo($payment->received_at);
    }

    private function recipient(Order $order): ?string
    {
        $email = $order->user_id !== null
            ? ($order->user?->role === 'customer' && $order->user->hasVerifiedEmail() ? $order->user->email : null)
            : ($order->sales_channel === 'walk_in' ? $order->contact_email : null);

        return is_string($email) && strlen($email) <= 254 && ! preg_match('/[\r\n]/', $email)
            && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false ? trim($email) : null;
    }
}
