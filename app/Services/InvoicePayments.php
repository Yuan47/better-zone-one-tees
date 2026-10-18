<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\Variant;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoicePayments
{
    public function __construct(private PayMongo $pay, private OrderFlow $flow, private ActivityRecorder $audit) {}

    public function start(Order $order, int $amount, ?User $actor, string $requestKey, bool $guestAuthorized = false): PaymentAttempt
    {
        $this->authorize($order, $actor, $guestAuthorized);
        if (! $this->pay->ready()) {
            $this->fail('PayMongo checkout is not configured. No payment was started.');
        }
        $attempt = DB::transaction(function () use ($order, $amount, $actor, $requestKey) {
            $this->creditCheck($order, $amount);
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($old = $this->replay($locked, $requestKey, 'paymongo', $amount)) {
                return $old;
            }
            $this->canReceive($locked, $amount);

            return $locked->payments()->create(['reference' => $requestKey, 'method' => 'paymongo', 'status' => 'creating', 'amount' => $amount, 'recorded_by' => $actor?->id]);
        }, 3);
        if (! $attempt->wasRecentlyCreated) {
            return $attempt;
        }
        try {
            $session = $this->pay->create($order->fresh()->load('items', 'user'), $attempt);
            DB::transaction(function () use ($order, $attempt, $session) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $payment = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                if ($payment->status !== 'creating' || $locked->inventory_released) {
                    $this->fail('This invoice changed while checkout was being created. Reconcile it before retrying.');
                }
                $payment->update($session + ['status' => 'pending']);
                $locked->update($session);
            }, 3);
        } catch (\Throwable $e) {
            $rejected = $e instanceof RequestException
                && in_array($e->response->status(), [400, 401, 403, 422], true)
                && is_array($e->response->json('errors')) && count($e->response->json('errors')) > 0
                && ! $e->response->json('data.id');
            PaymentAttempt::whereKey($attempt->id)->where('status', 'creating')->update([
                'status' => $rejected ? 'failed' : 'unknown',
                'reason' => $rejected ? 'PayMongo rejected checkout creation without creating a session (HTTP '.$e->response->status().').' : null,
            ]);
            throw $e;
        }

        return $attempt->fresh();
    }

    public function recordCash(Order $order, int $amount, User $actor, string $reason, string $requestKey): PaymentAttempt
    {
        abort_unless(in_array($actor->role, ['staff', 'admin'], true), 403);
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 1000) {
            $this->fail('Explain the cash payment received using 10–1,000 characters.');
        }

        return DB::transaction(function () use ($order, $amount, $actor, $reason, $requestKey) {
            $this->creditCheck($order, $amount);
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($old = $this->replay($locked, $requestKey, 'cash', $amount)) {
                return $old;
            }
            $this->canReceive($locked, $amount);
            $receipt = $locked->payments()->create(['reference' => $requestKey, 'method' => 'cash', 'status' => 'paid', 'amount' => $amount, 'recorded_by' => $actor->id, 'reason' => trim($reason), 'received_at' => now()]);
            $this->applyReceived($locked, $receipt, 'cash_'.$requestKey, now());
            if (! $this->audit->record($actor, 'invoice.payment_received', $locked, ['amount' => $amount, 'method' => 'cash', 'reason' => trim($reason)])) {
                throw new \RuntimeException('Cash receipt could not be audited.');
            }

            return $receipt;
        }, 3);
    }

    private function authorize(Order $order, ?User $actor, bool $guestAuthorized = false): void
    {
        if ($actor && $actor->role === null && $actor->exists) {
            $actor = $actor->fresh();
        }
        abort_unless(($actor && (in_array($actor->role, ['staff', 'admin'], true) || ($actor->role === 'customer' && $actor->id === $order->user_id)))
            || ($guestAuthorized && ! $actor && ! $order->user_id && $order->sales_channel === 'walk_in'), 403);
    }

    private function replay(Order $order, string $key, string $method, int $amount): ?PaymentAttempt
    {
        if (! Str::isUuid($key)) {
            $this->fail('Refresh this invoice before submitting the payment again.');
        }
        $old = PaymentAttempt::where('reference', $key)->lockForUpdate()->first();
        if ($old && ($old->order_id !== $order->id || $old->method !== $method || $old->amount !== $amount)) {
            $this->fail('This payment reference has already been used for a different request.');
        }

        return $old;
    }

    private function canReceive(Order $order, int $amount): void
    {
        if ($order->inventory_released || $order->status === 'cancelled' || $order->outstandingBalance() === 0) {
            $this->fail('This invoice is closed or already paid.');
        }
        if ($amount < 100 || $amount > $order->outstandingBalance()) {
            $this->fail('Payment must be at least ₱1.00 and cannot exceed the remaining balance.');
        }
        // Credit checks may have opened a repeatable-read snapshot before this
        // invoice lock. Current reads must see a checkout another request saved.
        if ($order->payments()->whereIn('status', PaymentAttempt::ACTIVE)->lockForUpdate()->first()
            || (! $order->payments()->lockForUpdate()->first() && $order->checkout_session_id)) {
            $this->fail('Check or close the existing online checkout before recording another payment. An uncertain checkout cannot be replaced.');
        }
        if ($amount < $order->outstandingBalance()) {
            app(InvoiceTerms::class)->validatePartial($order);
            $order->update(['payment_plan' => 'partial']);
            if (! $order->due_at) {
                $due = $order->created_at->copy()->addDays(app(InvoiceTerms::class)->days());
                if ($due->isPast()) {
                    $this->fail('The original payment deadline has passed. The owner must log an agreed deadline before offering installments.');
                }
                $order->update(['payment_plan' => 'partial', 'due_at' => $due, 'original_due_at' => $due]);
            }
            if (! $order->credit_phone_hash) {
                $order->update(['credit_phone_hash' => InstallmentCredit::phoneHash($order->phone)]);
            }
        }
    }

    private function creditCheck(Order $order, int $amount): void
    {
        $current = $order->fresh();
        if ($current->payment_plan !== 'partial' && $amount > 0 && $amount < $current->outstandingBalance()) {
            app(InstallmentCredit::class)->assertAvailable($current->user_id, $current->phone, $current->id);
        }
    }

    public function receiveProvider(PaymentAttempt $attempt, array $payment, bool $signedWebhook = false): string
    {
        return DB::transaction(function () use ($attempt, $payment, $signedWebhook) {
            $order = Order::whereKey($attempt->order_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $attributes = $payment['attributes'] ?? [];
            $id = $payment['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^pay_[A-Za-z0-9_]+$/D', $id) || ($attributes['status'] ?? null) !== 'paid'
                || ($attributes['currency'] ?? null) !== 'PHP' || ($attributes['amount'] ?? null) !== $locked->amount
                || ((! $signedWebhook || array_key_exists('livemode', $attributes)) && ($attributes['livemode'] ?? null) !== (config('shop.paymongo.mode') === 'live'))) {
                $this->fail('The provider payment does not match this installment. No invoice amount was changed.');
            }
            if ($locked->status === 'paid') {
                if ($locked->payment_id !== $id) {
                    $this->fail('This checkout already has a different confirmed payment. Owner reconciliation is required.');
                }

                return $order->payment_status;
            }
            if ($locked->method !== 'paymongo' || $locked->status === 'expired' || $order->inventory_released || $locked->amount > $order->outstandingBalance()
                || PaymentAttempt::where('payment_id', $id)->whereKeyNot($locked->id)->exists()
                || Order::where('payment_id', $id)->whereKeyNot($order->id)->exists()) {
                $this->fail('This payment needs owner reconciliation before it can be applied.');
            }
            $timestamp = $attributes['paid_at'] ?? null;
            if ($timestamp !== null && (! is_int($timestamp) || $timestamp < 1 || $timestamp > now()->timestamp + 300 || $timestamp < $locked->created_at->timestamp - 300)) {
                $this->fail('The provider payment date could not be verified.');
            }
            $receivedAt = $timestamp ? Carbon::createFromTimestamp($timestamp, 'Asia/Manila') : now();
            $locked->update(['status' => 'paid', 'payment_id' => $id, 'received_at' => $receivedAt]);
            $this->applyReceived($order, $locked, $id, $receivedAt);

            // Provider confirmation is recorded in the immutable receipt itself; no actor is invented.
            return $order->fresh()->payment_status;
        }, 3);
    }

    private function applyReceived(Order $order, PaymentAttempt $receipt, string $paymentId, $receivedAt): void
    {
        $received = (int) $order->amount_paid + $receipt->amount;
        if ($received > $order->total) {
            $this->fail('Payment exceeds the invoice balance.');
        }
        $order->update(['amount_paid' => $received]);
        if ($received === $order->total) {
            $this->flow->paid($order, $paymentId, $receivedAt);
            if ($order->sales_channel === 'walk_in') {
                $order->update(['status' => 'completed', 'walk_in_received_at' => $receivedAt]);
            }
        } else {
            $order->update(['payment_status' => 'partially_paid', 'payment_plan' => 'partial', 'status' => 'awaiting_payment']);
        }
    }

    public function check(Order $order, bool $close = false): string
    {
        $order = $order->fresh();
        if ($order->payment_status === 'paid' || $order->inventory_released) {
            return $order->payment_status === 'paid' ? 'paid' : 'cancelled';
        }
        $attempt = $order->payments()->where('method', 'paymongo')->latest('id')->first();
        if ($attempt && $attempt->status === 'failed' && ! $attempt->checkout_session_id) {
            if (! $close || $order->amount_paid > 0) {
                return $order->payment_status;
            }

            return DB::transaction(function () use ($order) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->amount_paid > 0 || $locked->payments()->where('status', 'paid')->exists()
                    || $locked->payments()->whereIn('status', PaymentAttempt::ACTIVE)->exists() || $locked->checkout_session_id) {
                    $this->fail('This invoice has received money or another checkout needs reconciliation. No stock was released.');
                }
                $this->flow->cancel($locked);

                return 'cancelled';
            }, 3);
        }
        if (! $attempt || ! $attempt->checkout_session_id) {
            $this->fail('The owner must recover this invoice payment reference before another online or cash payment can be recorded.');
        }
        if (in_array($attempt->status, ['paid', 'expired'], true)) {
            return $order->payment_status;
        }
        $session = $this->pay->retrieve($attempt->checkout_session_id);
        $this->validateSession($attempt, $session);
        $result = $this->applySession($attempt, $session);
        if ($result !== 'pending' || ! $close) {
            return $result;
        }
        try {
            $this->pay->expire($attempt->checkout_session_id);
        } catch (\Throwable $e) {
            // Expiry can be refused because payment completed or is processing. Always retrieve truth again.
        }
        $session = $this->pay->retrieve($attempt->checkout_session_id);
        $this->validateSession($attempt, $session);

        return $this->applySession($attempt, $session);
    }

    public function recover(Order $order, string $id): string
    {
        $attempt = $order->payments()->where('method', 'paymongo')->whereIn('status', ['creating', 'unknown'])->latest('id')->first();
        if (! $attempt || $attempt->checkout_session_id) {
            $this->fail('There is no uncertain payment awaiting a checkout reference.');
        }
        $session = $this->pay->retrieve($id);
        $this->validateSession($attempt, $session, $id, true);
        DB::transaction(function () use ($order, $attempt, $id, $session) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $payment = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($payment->checkout_session_id || ! in_array($payment->status, ['creating', 'unknown'], true) || $locked->inventory_released) {
                $this->fail('The payment changed during recovery.');
            }
            if (PaymentAttempt::where('checkout_session_id', $id)->whereKeyNot($payment->id)->exists() || Order::where('checkout_session_id', $id)->whereKeyNot($order->id)->exists()) {
                $this->fail('This checkout is already linked to another payment.');
            }
            $url = data_get($session, 'attributes.checkout_url');
            $fields = ['checkout_session_id' => $id, 'checkout_url' => is_string($url) && str_starts_with($url, 'https://checkout.paymongo.com/') ? $url : null];
            $payment->update($fields + ['status' => 'pending']);
            $locked->update($fields);
        }, 3);

        return $this->applySession($attempt->fresh(), $session);
    }

    private function validateSession(PaymentAttempt $attempt, array $session, ?string $id = null, bool $recover = false): void
    {
        if (($session['id'] ?? null) !== ($id ?? $attempt->checkout_session_id) || data_get($session, 'attributes.reference_number') !== $attempt->order->number
            || data_get($session, 'attributes.livemode') !== (config('shop.paymongo.mode') === 'live')
            || ($recover && ! $attempt->legacy && data_get($session, 'attributes.metadata.invoice_payment_reference') !== $attempt->reference)) {
            $this->fail('The checkout reference, invoice, installment reference or mode does not match.');
        }
        $lines = data_get($session, 'attributes.line_items');
        $sum = 0;
        if (! is_array($lines) || ! $lines) {
            $this->fail('The installment amount could not be verified.');
        }
        foreach ($lines as $line) {
            if (($line['currency'] ?? null) !== 'PHP' || ! is_int($line['amount'] ?? null) || ! is_int($line['quantity'] ?? null) || $line['amount'] < 0 || $line['quantity'] < 1) {
                $this->fail('The installment amount could not be verified.');
            }
            $sum += $line['amount'] * $line['quantity'];
        }
        if ($sum !== $attempt->amount) {
            $this->fail('The checkout amount differs from the saved installment.');
        }
    }

    private function applySession(PaymentAttempt $attempt, array $session): string
    {
        $payments = data_get($session, 'attributes.payments');
        if (! is_array($payments)) {
            $this->fail('Payment details are unavailable. Keep the invoice for reconciliation.');
        }
        $paid = array_values(array_filter($payments, fn ($p) => data_get($p, 'attributes.status') === 'paid'));
        if (count($paid) > 1) {
            $this->fail('This checkout contains multiple paid transactions. Owner reconciliation is required.');
        }
        if ($paid) {
            return $this->receiveProvider($attempt, $paid[0]);
        }
        if (data_get($session, 'attributes.status') !== 'expired') {
            return 'pending';
        }
        foreach ($payments as $payment) {
            if (! in_array(data_get($payment, 'attributes.status'), ['failed', 'cancelled'], true)) {
                $this->fail('A payment is still unsettled. Another payment cannot be started yet.');
            }
        }
        $neverStarted = array_key_exists('payment_intent', $session['attributes']) && $session['attributes']['payment_intent'] === null;
        if (! $neverStarted && ! in_array(data_get($session, 'attributes.payment_intent.attributes.status'), ['awaiting_payment_method', 'cancelled'], true)) {
            $this->fail('Payment processing still needs verification.');
        }

        return DB::transaction(function () use ($attempt) {
            $order = Order::whereKey($attempt->order_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid' || $order->payment_status === 'paid') {
                return $order->payment_status;
            }
            $locked->update(['status' => 'expired', 'checkout_url' => null]);
            if ($order->checkout_session_id === $locked->checkout_session_id) {
                $order->update(['checkout_url' => null]);
            }
            if ($order->amount_paid > 0) {
                return 'partially_paid';
            }
            if (! $order->inventory_released) {
                foreach ($order->items()->orderBy('variant_id')->get() as $item) {
                    $variant = Variant::whereKey($item->variant_id)->lockForUpdate()->firstOrFail();
                    if ($variant->reserved < $item->quantity) {
                        $this->fail('The inventory reservation needs review.');
                    }
                    $variant->decrement('reserved', $item->quantity);
                }
                $order->update(['status' => 'cancelled', 'inventory_released' => true]);
            }

            return 'cancelled';
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
