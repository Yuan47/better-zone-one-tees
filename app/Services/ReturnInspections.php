<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnInspection;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReturnInspections
{
    public static function photoRules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072', 'dimensions:max_width=6000,max_height=6000'];
    }

    public function storePhoto(?UploadedFile $photo): ?string
    {
        if (! $photo) {
            return null;
        }
        $path = $photo->store('refund-evidence', 'local');
        if (! is_string($path) || $path === '') {
            $this->fail('The private refund photo could not be saved. Try again before submitting.');
        }

        return $path;
    }

    public function policy(): array
    {
        $saved = json_decode(DB::table('shop_settings')->where('key', 'return_policy')->value('value') ?? '{}', true);

        return ['days' => max(1, min(30, (int) ($saved['days'] ?? 7))),
            'delivery_eligible' => (bool) ($saved['delivery_eligible'] ?? true)];
    }

    public function snapshot(Order $order, ?Carbon $requestedAt = null): array
    {
        $delivered = $order->customer_confirmed_at ?? $order->walk_in_received_at;
        if (! $order->deliveryConfirmed() || ! $delivered) {
            $this->fail('Return requests require confirmed delivery or a completed walk-in handover.');
        }
        $policy = $this->policy();
        $deadline = $delivered->copy()->addDays($policy['days']);

        return ['policy_days' => $policy['days'], 'policy_delivery_eligible' => $policy['delivery_eligible'],
            'policy_delivered_at' => $delivered, 'policy_deadline_at' => $deadline,
            'policy_late' => ($requestedAt ?? now())->gt($deadline)];
    }

    public function record(Refund $refund, User $owner, array $data, ?string $privatePhotoPath = null): ReturnInspection
    {
        abort_unless($owner->role === 'admin', 403);

        return DB::transaction(function () use ($refund, $owner, $data, $privatePhotoPath) {
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['requested', 'approved'], true) || $locked->provider_refund_id || $locked->return_inspection_id) {
                $this->fail('Only an unprocessed request without an inspection can record or reuse a return inspection.');
            }
            if ($order->payment_status !== 'paid' || ! $order->deliveryConfirmed()) {
                $this->fail('Inspection requires a fully paid order and confirmed delivery.');
            }
            $snapshot = $locked->policy_days === null ? $this->snapshot($order, $locked->created_at) : $locked->only([
                'policy_days', 'policy_delivery_eligible', 'policy_delivered_at', 'policy_deadline_at', 'policy_late']);
            if ($snapshot['policy_late'] && mb_strlen(trim($data['late_exception_reason'] ?? '')) < 10) {
                $this->fail('This request is outside its return window. Record the owner’s late-return exception reason.');
            }
            $exception = $snapshot['policy_late'] ? trim($data['late_exception_reason']) : null;
            if (! empty($data['existing_inspection_id'])) {
                if ($privatePhotoPath) {
                    $this->fail('Reuse the original inspection without uploading a different inspection photo.');
                }
                $inspection = ReturnInspection::where('order_id', $order->id)->whereKey($data['existing_inspection_id'])->first();
                if (! $inspection || array_sum($inspection->remaining()) < 100) {
                    $this->fail('Choose an inspection from this invoice with an unused eligible amount.');
                }
                $locked->update($snapshot + ['return_inspection_id' => $inspection->id, 'late_exception_reason' => $exception]);
                $this->audit($owner, $locked, 'Reused immutable return inspection #'.$inspection->id.'.');

                return $inspection;
            }
            if (! $privatePhotoPath && mb_strlen(trim($data['photo_exception_reason'] ?? '')) < 10) {
                $this->fail('Upload a private inspection photo or record why a photo is unavailable.');
            }
            if ($privatePhotoPath && (! str_starts_with($privatePhotoPath, 'refund-evidence/') || ! Storage::disk('local')->exists($privatePhotoPath))) {
                $this->fail('The private inspection photo could not be saved.');
            }
            if (mb_strlen(trim($data['inspection_note'] ?? '')) < 10) {
                $this->fail('Record what was checked during the physical return inspection.');
            }
            $items = $order->items()->orderBy('id')->get();
            $prior = ReturnInspection::where('order_id', $order->id)->get();
            $inspected = [];
            foreach ($prior as $case) {
                foreach ($case->items as $item) {
                    $inspected[$item['order_item_id']] = ($inspected[$item['order_item_id']] ?? 0) + $item['quantity'];
                }
            }
            $base = (int) $items->sum('total');
            if ($base < 1 || $items->isEmpty()) {
                $this->fail('This invoice needs its original item values before a physical return can be inspected.');
            }
            // Cumulative allocation preserves the exact discounted clothing subtotal, including centavo rounding.
            $netLines = [];
            $cumulative = 0;
            foreach ($items as $item) {
                $before = (int) round($order->subtotal * ($cumulative / $base));
                $cumulative += $item->total;
                $netLines[$item->id] = (int) round($order->subtotal * ($cumulative / $base)) - $before;
            }
            $snapshots = [];
            $seen = [];
            foreach ($data['items'] ?? [] as $entry) {
                $id = (int) ($entry['order_item_id'] ?? 0);
                $quantity = (int) ($entry['quantity'] ?? 0);
                if ($quantity === 0) {
                    continue;
                }
                $item = $items->firstWhere('id', $id);
                $previous = $inspected[$id] ?? 0;
                if (! $item || isset($seen[$id]) || $quantity < 1 || $quantity + $previous > $item->quantity
                    || ! in_array($entry['condition'] ?? '', ['good', 'damaged', 'wrong_item'], true)) {
                    $this->fail('Returned items must belong to this invoice, have a recorded condition, and not exceed uninspected ordered quantities.');
                }
                $seen[$id] = true;
                $value = (int) round($netLines[$id] * (($previous + $quantity) / $item->quantity))
                    - (int) round($netLines[$id] * ($previous / $item->quantity));
                $snapshots[] = ['order_item_id' => $id, 'name' => $item->name, 'size' => $item->size, 'color' => $item->color,
                    'quantity' => $quantity, 'condition' => $entry['condition'], 'eligible_value' => $value];
            }
            if (! $snapshots) {
                $this->fail('Record at least one physically returned clothing item.');
            }
            $delivery = ($snapshot['policy_delivery_eligible'] && ($data['include_delivery'] ?? false))
                ? max(0, $order->shipping - (int) $prior->sum('delivery_value')) : 0;
            $inspection = ReturnInspection::create(['order_id' => $order->id, 'inspected_by' => $owner->id, 'items' => $snapshots,
                'clothing_value' => array_sum(array_column($snapshots, 'eligible_value')), 'delivery_value' => $delivery,
                'inspection_note' => trim($data['inspection_note']), 'photo_path' => $privatePhotoPath,
                'photo_exception_reason' => $privatePhotoPath ? null : trim($data['photo_exception_reason']),
                'policy_days' => $snapshot['policy_days'], 'policy_delivery_eligible' => $snapshot['policy_delivery_eligible'],
                'delivered_at' => $snapshot['policy_delivered_at'], 'deadline_at' => $snapshot['policy_deadline_at'],
                'requested_at' => $locked->created_at, 'late_exception_reason' => $snapshot['policy_late'] ? trim($data['late_exception_reason']) : null,
                'inspected_at' => now()]);
            $locked->update($snapshot + ['return_inspection_id' => $inspection->id, 'late_exception_reason' => $exception]);
            $this->audit($owner, $locked, 'Recorded immutable return inspection #'.$inspection->id.': '.trim($data['inspection_note']));

            return $inspection;
        }, 3);
    }

    public function allocation(Refund $refund, int $amount): array
    {
        $inspection = $refund->returnInspection()->first();
        if (! $inspection || (int) $inspection->order_id !== (int) $refund->order_id) {
            $this->fail('Record or reuse a physical return inspection before approving or recording this refund.');
        }
        if ($refund->policy_late && mb_strlen(trim($refund->late_exception_reason ?? '')) < 10) {
            $this->fail('Record an owner exception for this late request before approval or payout, including when reusing an inspection.');
        }
        if ((! $inspection->photo_path && mb_strlen(trim($inspection->photo_exception_reason ?? '')) < 10)
            || ($inspection->photo_path && ! Storage::disk('local')->exists($inspection->photo_path))
            || ($inspection->requested_at->gt($inspection->deadline_at) && mb_strlen(trim($inspection->late_exception_reason ?? '')) < 10)) {
            $this->fail('This inspection needs valid private evidence or an owner exception, including any late-return exception.');
        }
        $remaining = $inspection->remaining($refund->id);
        if ($amount < 100 || $amount > array_sum($remaining)) {
            $this->fail('The refund exceeds the inspected clothing and eligible delivery amount remaining on this return.');
        }
        $clothing = min($amount, $remaining['clothing']);

        return ['clothing_amount' => $clothing, 'delivery_amount' => $amount - $clothing];
    }

    private function audit(User $owner, Refund $refund, string $reason): void
    {
        if (! app(ActivityRecorder::class)->record($owner, 'refund.inspected', $refund, ['reason' => $reason])) {
            throw new \RuntimeException('Return inspection audit could not be recorded.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['refund' => $message]);
    }
}
