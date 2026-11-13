<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerPurchases
{
    public const STATUSES = [
        'all' => 'All',
        'to_pay' => 'To Pay',
        'packing' => 'Packing',
        'ready' => 'Ready for delivery',
        'to_ship' => 'To Ship',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'return_refund' => 'Return / Refund',
    ];

    public function page(Request $request): array
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $filters = ['status' => $filters['status'] ?? 'all', 'q' => trim($filters['q'] ?? '')];
        $ownedOrders = Order::query()->where('user_id', $request->user()->id);
        $query = $this->status(clone $ownedOrders, $filters['status']);
        if ($filters['q'] !== '') {
            $search = '%'.$filters['q'].'%';
            $query->where(function (Builder $orders) use ($search) {
                $orders->where('number', 'like', $search)
                    ->orWhereHas('items', function (Builder $items) use ($search) {
                        $items->where('name', 'like', $search)
                            ->orWhere('color', 'like', $search)
                            ->orWhere('size', 'like', $search);
                    });
            });
        }
        $tabs = [];
        foreach (self::STATUSES as $status => $label) {
            $tabs[] = ['status' => $status, 'label' => $label,
                'count' => $this->status(clone $ownedOrders, $status)->count()];
        }
        $orders = $query->with('items')->withCount('refunds')->latest('created_at')->latest('id')
            ->paginate(15)->withQueryString();

        return compact('orders', 'filters', 'tabs');
    }

    private function status(Builder $query, string $status): Builder
    {
        return match ($status) {
            'to_pay' => $query->where('status', '!=', 'cancelled')->where('inventory_released', false)
                ->whereIn('payment_status', ['pending', 'partially_paid'])->whereColumn('total', '>', 'amount_paid'),
            'packing' => $query->whereDeliveryStage('packing'),
            'ready' => $query->whereDeliveryStage('ready'),
            'to_ship' => $query->whereDeliveryStage('courier'),
            'completed' => $query->whereDeliveryStage('delivered'),
            'cancelled' => $query->where(fn (Builder $closed) => $closed->where('status', 'cancelled')->orWhere('inventory_released', true)),
            'return_refund' => $query->whereHas('refunds'),
            default => $query,
        };
    }
}
