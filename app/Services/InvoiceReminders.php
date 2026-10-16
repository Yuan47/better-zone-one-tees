<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentReminder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class InvoiceReminders
{
    public function configured(): bool
    {
        $key = config('services.brevo.api_key');
        $name = config('services.brevo.from_name');

        return is_string($key) && trim($key) !== '' && ! preg_match('/[\r\n]/', $key)
            && $this->validEmail(config('services.brevo.from_email'))
            && is_string($name) && trim($name) !== '' && mb_strlen($name) <= 100 && ! preg_match('/[\r\n]/', $name);
    }

    public function ready(): bool
    {
        return config('services.brevo.payment_reminders_enabled') === true && $this->configured();
    }

    public function run(?CarbonInterface $now = null, bool $dryRun = false): array
    {
        $clock = CarbonImmutable::instance($now ?? now())->setTimezone('Asia/Manila');
        $counts = ['enabled' => config('services.brevo.payment_reminders_enabled') === true, 'configured' => $this->configured(),
            'dry_run' => $dryRun, 'eligible' => 0, 'sent' => 0, 'failed' => 0, 'unknown' => 0, 'skipped' => 0];
        if (! $dryRun && ! $this->ready()) {
            return $counts;
        }
        foreach ([3, 1] as $days) {
            $day = $clock->startOfDay()->addDays($days);
            Order::with('user')->whereIn('payment_status', ['pending', 'partially_paid'])->where('status', '!=', 'cancelled')
                ->where('inventory_released', false)->whereColumn('total', '>', 'amount_paid')
                ->where('due_at', '>=', $day)->where('due_at', '<', $day->addDay())
                ->orderBy('id')->chunkById(100, function ($orders) use ($clock, $now, $days, $dryRun, &$counts) {
                    foreach ($orders as $order) {
                        $snapshot = $order->due_at->format('Y-m-d H:i:s');
                        if (! $this->eligible($order, $snapshot, $days, $clock) || $this->recipient($order) === null
                            || PaymentReminder::where('order_id', $order->id)->where('due_at', $snapshot)->where('days_before', $days)->exists()) {
                            $counts['skipped']++;

                            continue;
                        }
                        if ($dryRun) {
                            $counts['eligible']++;

                            continue;
                        }
                        $reminder = $this->claim($order->id, $snapshot, $days, $clock);
                        if (! $reminder) {
                            $counts['skipped']++;

                            continue;
                        }
                        $counts['eligible']++;
                        $counts[$this->deliver($reminder, $now === null ? null : $clock)]++;
                    }
                });
        }

        return $counts;
    }

    private function claim(int $orderId, string $snapshot, int $days, CarbonImmutable $clock): ?PaymentReminder
    {
        return DB::transaction(function () use ($orderId, $snapshot, $days, $clock) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $order->load('user');
            $recipient = $this->recipient($order);
            if (! $this->eligible($order, $snapshot, $days, $clock) || $recipient === null
                || PaymentReminder::where('order_id', $order->id)->where('due_at', $snapshot)->where('days_before', $days)->exists()) {
                return null;
            }

            // Persist the unique claim before HTTP. An interrupted or uncertain send is never automatically repeated.
            return PaymentReminder::create(['order_id' => $order->id, 'due_at' => $snapshot, 'days_before' => $days,
                'claim_key' => (string) Str::uuid(), 'recipient' => $recipient, 'amount' => $order->outstandingBalance(), 'status' => 'claimed']);
        }, 3);
    }

    private function deliver(PaymentReminder $reminder, ?CarbonImmutable $clock): string
    {
        // One attempt only: retrying a database transaction containing HTTP could send a duplicate email.
        return DB::transaction(function () use ($reminder, $clock) {
            $order = Order::whereKey($reminder->order_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentReminder::whereKey($reminder->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'claimed') {
                return 'skipped';
            }
            $order->load('user');
            $recipient = $this->recipient($order);
            $snapshot = $locked->due_at->format('Y-m-d H:i:s');
            $sendClock = $clock ?? CarbonImmutable::instance(now())->setTimezone('Asia/Manila');
            if (! $this->ready() || ! $this->eligible($order, $snapshot, $locked->days_before, $sendClock) || $recipient === null) {
                $locked->update(['status' => 'skipped', 'failure_reason' => 'invoice_or_recipient_changed_before_send']);

                return 'skipped';
            }
            $amount = $order->outstandingBalance();
            $name = $order->user?->name ?? $order->recipient;
            $invoiceUrl = $order->user_id ? route('orders.show', $order)
                : URL::temporarySignedRoute('guest.orders.show', $order->due_at->copy()->addDays(7), ['order' => $order->id]);
            $dueLabel = $order->due_at->copy()->setTimezone('Asia/Manila')->format('F j, Y');
            $days = $locked->days_before;
            try {
                $html = view('mail.payment-reminder', compact('order', 'amount', 'name', 'invoiceUrl', 'dueLabel', 'days'))->render();
            } catch (\Throwable $exception) {
                $locked->update(['status' => 'failed', 'failure_reason' => 'email_render_failed']);

                return 'failed';
            }
            $locked->update(['recipient' => $recipient, 'amount' => $amount, 'attempted_at' => now()]);
            try {
                $response = Http::withHeaders(['api-key' => trim(config('services.brevo.api_key'))])->acceptJson()->asJson()
                    ->withoutRedirecting()->connectTimeout(3)->timeout(8)
                    ->post('https://api.brevo.com/v3/smtp/email', [
                        'sender' => ['name' => trim(config('services.brevo.from_name')), 'email' => trim(config('services.brevo.from_email'))],
                        'to' => [['email' => $recipient, 'name' => $name]],
                        'subject' => 'Payment reminder: '.$order->number.' is due in '.$days.' '.($days === 1 ? 'day' : 'days'),
                        'htmlContent' => $html,
                        'textContent' => 'Hello '.$name.', your Zone One Tees invoice '.$order->number.' has a remaining balance of PHP '.number_format($amount / 100, 2)
                            .' due on '.$dueLabel.' (Asia/Manila). View your invoice: '.$invoiceUrl.'. If a payment is still being verified, check its status on your invoice.',
                        'headers' => ['IdempotencyKey' => $locked->claim_key], 'tags' => ['invoice-payment-reminder'],
                    ]);
            } catch (\Throwable $exception) {
                $locked->update(['status' => 'unknown', 'failure_reason' => 'provider_outcome_unknown']);

                return 'unknown';
            }
            $message = $response->json('messageId');
            if ($response->status() === 201 && is_string($message) && trim($message) !== '' && strlen($message) <= 255
                && ! PaymentReminder::where('provider_message_id', $message)->whereKeyNot($locked->id)->exists()) {
                $locked->update(['status' => 'sent', 'provider_message_id' => $message, 'provider_status' => 201, 'sent_at' => now(), 'failure_reason' => null]);

                return 'sent';
            }
            $failed = in_array($response->status(), [400, 401, 402, 403, 404, 405, 406, 410, 413, 415, 422, 429], true);
            $locked->update(['status' => $failed ? 'failed' : 'unknown', 'provider_status' => $response->status(),
                'failure_reason' => $failed ? 'provider_rejected_request' : 'provider_acceptance_not_verified']);

            return $failed ? 'failed' : 'unknown';
        }, 1);
    }

    private function eligible(Order $order, string $snapshot, int $days, CarbonImmutable $clock): bool
    {
        return in_array($order->payment_status, ['pending', 'partially_paid'], true) && $order->status !== 'cancelled' && ! $order->inventory_released
            && $order->outstandingBalance() > 0 && $order->due_at !== null && $order->due_at->format('Y-m-d H:i:s') === $snapshot
            && $order->due_at->copy()->setTimezone('Asia/Manila')->toDateString() === $clock->addDays($days)->toDateString();
    }

    private function recipient(Order $order): ?string
    {
        if ($order->user_id !== null) {
            $email = $order->user?->role === 'customer' ? $order->user->email : null;
        } else {
            $email = $order->sales_channel === 'walk_in' ? $order->contact_email : null;
        }

        return $this->validEmail($email) ? trim($email) : null;
    }

    private function validEmail(mixed $email): bool
    {
        return is_string($email) && strlen($email) <= 254 && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }
}
