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

    public function validatePartial(Order $order): void
    {
        if (! $this->eligible($order)) {
            throw ValidationException::withMessages(['amount' => 'Partial payments are available only when the discounted clothing subtotal is more than ₱1,000.00. Delivery fees do not count.']);
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
