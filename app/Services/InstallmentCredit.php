<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstallmentCredit
{
    /** The caller must acquire credit locks before inventory or invoice locks. */
    public function assertAvailable(?int $userId, ?string $phone, ?int $exceptOrderId = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Installment credit checks require a database transaction.');
        }

        $customer = $userId === null ? null : User::find($userId);
        if ($userId !== null && (! $customer || $customer->role !== 'customer')) {
            $this->fail('Partial payments must belong to a customer account or an identified walk-in guest.');
        }
        $incomingPhone = self::normalizedPhone($phone);
        if ($userId === null && $incomingPhone === null) {
            $this->fail('Enter a valid Philippine mobile number before offering a guest partial payment.');
        }
        $phones = array_values(array_unique(array_filter([$incomingPhone, self::normalizedPhone($customer?->phone)])));
        $phoneVariants = [];
        foreach ($phones as $canonical) {
            $mobile = substr($canonical, 3);
            array_push($phoneVariants, $canonical, '63'.$mobile, '0'.$mobile, $mobile);
        }
        $userIds = $userId === null ? [] : [$userId];
        if ($phoneVariants) {
            $userIds = array_merge($userIds, User::where('role', 'customer')->whereIn('phone', $phoneVariants)->pluck('id')->all());
        }
        $userIds = array_values(array_unique($userIds));
        $phoneHashes = array_map(fn ($value) => self::phoneHash($value), $phones);
        $keys = array_merge($phoneHashes, array_map(fn ($id) => hash('sha256', 'user:'.$id), $userIds));
        sort($keys, SORT_STRING);
        foreach (array_unique($keys) as $key) {
            DB::table('credit_locks')->insertOrIgnore(['identity_hash' => $key]);
            DB::table('credit_locks')->where('identity_hash', $key)->lockForUpdate()->first();
        }

        // A locking read sees invoices committed while this request waited for the identity lock.
        $existing = $this->openBalances()->where('payment_plan', 'partial')
            ->when($exceptOrderId !== null, fn ($query) => $query->whereKeyNot($exceptOrderId))
            ->where(fn ($query) => $query->whereIn('user_id', $userIds)->orWhereIn('credit_phone_hash', $phoneHashes)
                ->orWhere(fn ($legacy) => $legacy->whereNull('credit_phone_hash')->whereIn('phone', $phoneVariants)))
            ->orderBy('id')->lockForUpdate()->first();
        if ($existing) {
            $this->fail('Settle the existing installment invoice before opening another partial-payment order. A new order paid in full is still available.');
        }
    }

    public static function phoneHash(?string $phone): ?string
    {
        $canonical = self::normalizedPhone($phone);

        return $canonical === null ? null : hash('sha256', 'phone:'.$canonical);
    }

    private static function normalizedPhone(?string $phone): ?string
    {
        $value = preg_replace('/[\s().-]+/u', '', trim($phone ?? ''));
        if (! is_string($value) || ! preg_match('/^(?:\+?63|0)?(9[0-9]{9})$/D', $value, $matches)) {
            return null;
        }

        return '+63'.$matches[1];
    }

    /** @return Collection<int, Order> */
    public function alertsFor(?User $user): Collection
    {
        if ($user && $user->role === null && $user->exists) {
            $user = $user->fresh();
        }
        if (! $user || $user->role !== 'customer') {
            return new Collection;
        }

        return $this->openBalances()->where('user_id', $user->id)->orderBy('due_at')->orderBy('id')->get();
    }

    private function openBalances(): Builder
    {
        return Order::whereIn('payment_status', ['pending', 'partially_paid'])->where('status', '!=', 'cancelled')
            ->where('inventory_released', false)->whereColumn('total', '>', 'amount_paid');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment_option' => $message]);
    }
}
