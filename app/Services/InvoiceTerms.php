<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceTerms
{
    public function days(): int
    {
        $saved = DB::table('shop_settings')->where('key', 'partial_payment_due_days')->value('value');

        return max(7, min(30, (int) ($saved ?? config('shop.partial_due_days', 7))));
    }

    public function minimumSubtotal(): int
    {
        return 100000;
    }

    public function eligible(Order $order): bool
    {
        return $order->subtotal > $this->minimumSubtotal();
    }

    public function validatePartial(Order $order, string $field = 'amount'): void
    {
        if (! $this->eligible($order)) {
            throw ValidationException::withMessages([$field => 'Partial payments are available only when the discounted clothing subtotal is more than ₱1,000.00. Delivery fees do not count.']);
        }
    }

    public static function initialMinimum(int $invoiceTotal): int
    {
        // Invoice amounts are integer centavos. Round an odd centavo upward.
        return intdiv($invoiceTotal + 1, 2);
    }

    public function minimumPayment(Order $order): int
    {
        if (! $this->eligible($order)) {
            return $order->outstandingBalance();
        }

        return $order->paidAmount() > 0 ? 100 : self::initialMinimum((int) $order->total);
    }

    public function validatePartialPayment(Order $order, int $amount, string $field = 'amount'): void
    {
        $this->validatePartial($order, $field);
        if ($order->paidAmount() === 0 && $amount < self::initialMinimum((int) $order->total)) {
            throw ValidationException::withMessages([$field => 'The first payment must be at least 50% of the original invoice total, including delivery (₱'.number_format(self::initialMinimum((int) $order->total) / 100, 2).'). Later payments may be at least ₱1.00, up to the remaining balance.']);
        }
        $remaining = $order->outstandingBalance() - $amount;
        if ($remaining > 0 && $remaining < 100) {
            throw ValidationException::withMessages([$field => 'Pay the remaining balance in full or leave at least ₱1.00 outstanding. A new payment cannot leave a balance below ₱1.00.']);
        }
    }

    public static function amount(mixed $value): int
    {
        if (! is_scalar($value) || ! preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', (string) $value, $parts)) {
            throw ValidationException::withMessages(['amount' => 'Enter a payment amount with no more than two decimal places.']);
        }
        $amount = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($amount < 100 || $amount > 4294967295) {
            throw ValidationException::withMessages(['amount' => 'Enter at least ₱1.00, up to the remaining invoice balance.']);
        }

        return $amount;
    }
}
