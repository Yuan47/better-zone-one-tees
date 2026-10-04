<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;

class Forecast
{
    public function linear(array $values): array
    {
        $n = count($values);
        if ($n < 3) {
            return ['a' => null, 'b' => null, 'prediction' => null, 'raw' => null];
        }
        $xs = range(1, $n);
        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($values) / $n;
        $numerator = 0;
        $denominator = 0;
        foreach ($values as $i => $value) {
            $numerator += ($xs[$i] - $meanX) * ($value - $meanY);
            $denominator += ($xs[$i] - $meanX) ** 2;
        }
        $b = $numerator / $denominator;
        $a = $meanY - $b * $meanX;
        $raw = $a + $b * ($n + 1);

        return ['a' => $a, 'b' => $b, 'prediction' => max(0, $raw), 'raw' => $raw];
    }

    public function growth(array $values): array
    {
        $n = count($values);
        if ($n < 2) {
            return ['rate' => null, 'prediction' => null, 'previous' => null, 'earlier' => null];
        }
        $previous = $values[$n - 1];
        $earlier = $values[$n - 2];
        if ($earlier <= 0) {
            return ['rate' => null, 'prediction' => null, 'previous' => $previous, 'earlier' => $earlier];
        }
        $rate = ($previous - $earlier) / $earlier;

        return ['rate' => $rate, 'prediction' => $previous * (1 + $rate), 'previous' => $previous, 'earlier' => $earlier];
    }

    private function periods(): array
    {
        $end = now()->startOfMonth();
        $first = Order::where('payment_status', 'paid')->where('paid_at', '<', $end)->min('paid_at');
        if (! $first) {
            return [];
        }
        $start = Carbon::parse($first)->startOfMonth();
        if ($start->lt($end->copy()->subMonths(6))) {
            $start = $end->copy()->subMonths(6);
        }
        $months = [];
        for ($date = $start->copy(); $date->lt($end); $date->addMonth()) {
            $months[] = $date->format('Y-m');
        }

        return $months;
    }

    private function summarize(array $values, array $months): array
    {
        return ['months' => $months, 'values' => $values, 'regression' => $this->linear($values), 'growth' => $this->growth($values), 'target' => now()->format('F Y')];
    }

    public function revenue(): array
    {
        $months = $this->periods();
        $totals = array_fill_keys($months, 0);
        Order::where('payment_status', 'paid')->where('paid_at', '<', now()->startOfMonth())->get()->each(function ($order) use (&$totals) {
            $month = $order->paid_at->format('Y-m');
            if (array_key_exists($month, $totals)) {
                $totals[$month] += $order->subtotal;
            }
        });

        return $this->summarize(array_values($totals), $months);
    }

    public function rows(): array
    {
        $months = $this->periods();
        $orders = Order::with('items')->where('payment_status', 'paid')->where('paid_at', '<', now()->startOfMonth())
            ->when(count($months) > 0, fn ($q) => $q->where('paid_at', '>=', $months[0].'-01'))->get();

        return Product::with('variants')->get()->map(function ($product) use ($months, $orders) {
            $totals = array_fill_keys($months, 0);
            $ids = $product->variants->pluck('id');
            foreach ($orders as $order) {
                $month = $order->paid_at->format('Y-m');
                if (array_key_exists($month, $totals)) {
                    $totals[$month] += $order->items->whereIn('variant_id', $ids)->sum('quantity');
                }
            }
            $values = array_values($totals);
            $summary = $this->summarize($values, $months);
            $prediction = $summary['regression']['prediction'];
            $available = $product->variants->sum(fn ($v) => $v->available());

            return $summary + ['name' => $product->name, 'category' => $product->category, 'sold' => array_sum($values),
                'forecast' => $prediction === null ? null : (int) ceil($prediction), 'available' => $available,
                'reorder' => $prediction === null ? null : max(0, (int) ceil($prediction) - $available)];
        })->all();
    }

    public function categories(): array
    {
        return collect($this->rows())->groupBy('category')->map(function ($rows, $name) {
            $months = $rows->first()['months'];
            $values = array_fill(0, count($months), 0);
            foreach ($rows as $row) {
                foreach ($row['values'] as $i => $value) {
                    $values[$i] += $value;
                }
            }

            return $this->summarize($values, $months) + ['name' => $name];
        })->values()->all();
    }
}
