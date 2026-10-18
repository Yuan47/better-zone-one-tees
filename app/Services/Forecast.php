<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\CarbonImmutable;

class Forecast
{
    public function linear(array $values): array
    {
        $n = count($values);
        if ($n < 3) {
            return ['a' => null, 'b' => null, 'prediction' => null, 'raw' => null, 'status' => 'insufficient_history'];
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

        return ['a' => $a, 'b' => $b, 'prediction' => max(0, $raw), 'raw' => $raw, 'status' => 'available'];
    }

    public function growth(array $values): array
    {
        $n = count($values);
        if ($n < 2) {
            return ['rate' => null, 'prediction' => null, 'previous' => null, 'earlier' => null, 'status' => 'insufficient_history'];
        }
        $previous = $values[$n - 1];
        $earlier = $values[$n - 2];
        if ($earlier <= 0) {
            return ['rate' => null, 'prediction' => null, 'previous' => $previous, 'earlier' => $earlier, 'status' => 'zero_baseline'];
        }
        $rate = ($previous - $earlier) / $earlier;

        return ['rate' => $rate, 'prediction' => $previous * (1 + $rate), 'previous' => $previous, 'earlier' => $earlier, 'status' => 'available'];
    }

    public function backtest(array $values, array $months = [], bool $roundUnits = false, ?string $firstObservedMonth = null): array
    {
        $results = [];
        foreach (['growth' => 2, 'regression' => 3] as $method => $minimum) {
            $points = [];
            for ($index = $minimum; $index < count($values); $index++) {
                // A product with no earlier paid sale would have shown Needs
                // history at the time, rather than issuing a zero prediction.
                if ($firstObservedMonth !== null && $months[$index] <= $firstObservedMonth) {
                    continue;
                }
                // The held-out month and all later values must stay out of the fitted model.
                $history = array_slice($values, 0, $index);
                $prediction = ($method === 'growth' ? $this->growth($history) : $this->linear($history))['prediction'];
                if ($prediction === null) {
                    continue;
                }
                $prediction = $roundUnits ? ceil($prediction) : $prediction;
                $points[] = ['month' => $months[$index] ?? null, 'training_months' => $index,
                    'actual' => $values[$index], 'prediction' => $prediction,
                    'absolute_error' => abs($values[$index] - $prediction)];
            }
            $measurable = count($points) >= 2;
            $error = array_sum(array_column($points, 'absolute_error'));
            $actual = array_sum(array_map(fn ($point) => abs($point['actual']), $points));
            $results[$method] = ['status' => $measurable ? 'available' : 'insufficient_history',
                'minimum_training_months' => $minimum, 'minimum_test_months' => 2,
                'tested_months' => count($points), 'points' => $points,
                'mae' => $measurable ? $error / count($points) : null,
                'wape' => $measurable && $actual > 0 ? $error / $actual * 100 : null];
        }

        return $results;
    }

    private function periods(): array
    {
        $end = CarbonImmutable::now('Asia/Manila')->startOfMonth();
        $first = Order::where('payment_status', 'paid')->where('paid_at', '<', $end)->min('paid_at');
        if (! $first) {
            return [];
        }

        return $this->completedPeriods(CarbonImmutable::parse($first, 'Asia/Manila')->startOfMonth(), $end);
    }

    private function completedPeriods(CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($start->lt($end->subMonths(6))) {
            $start = $end->subMonths(6);
        }
        $months = [];
        for ($date = $start; $date->lt($end); $date = $date->addMonth()) {
            $months[] = $date->format('Y-m');
        }

        return $months;
    }

    private function summarize(array $values, array $months, bool $roundUnits = false, bool $observed = true, ?string $firstObservedMonth = null): array
    {
        $regression = $this->linear($observed ? $values : []);
        $growth = $this->growth($observed ? $values : []);
        if (! $observed) {
            $regression['status'] = $growth['status'] = 'no_observed_sales';
        }

        return ['months' => $months, 'values' => $values, 'regression' => $regression, 'growth' => $growth,
            'backtest' => $this->backtest($observed ? $values : [], $months, $roundUnits, $firstObservedMonth),
            'target' => CarbonImmutable::now('Asia/Manila')->format('F Y')];
    }

    public function revenue(): array
    {
        $months = $this->periods();
        $totals = array_fill_keys($months, 0);
        Order::where('payment_status', 'paid')->where('paid_at', '<', CarbonImmutable::now('Asia/Manila')->startOfMonth())
            ->when(count($months) > 0, fn ($q) => $q->where('paid_at', '>=', $months[0].'-01'))
            ->get()->each(function ($order) use (&$totals) {
                $month = $order->paid_at->setTimezone('Asia/Manila')->format('Y-m');
                if (array_key_exists($month, $totals)) {
                    $totals[$month] += $order->subtotal;
                }
            });

        return $this->summarize(array_values($totals), $months);
    }

    public function rows(): array
    {
        $end = CarbonImmutable::now('Asia/Manila')->startOfMonth();
        $paidItems = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('variants', 'variants.id', '=', 'order_items.variant_id')
            ->where('orders.payment_status', 'paid')->where('orders.paid_at', '<', $end)
            ->where('order_items.quantity', '>', 0);
        $firstSales = (clone $paidItems)->selectRaw('variants.product_id, MIN(orders.paid_at) AS first_paid_at')
            ->groupBy('variants.product_id')->pluck('first_paid_at', 'product_id');
        $monthlyUnits = [];
        (clone $paidItems)->where('orders.paid_at', '>=', $end->subMonths(6))
            ->select(['order_items.id', 'variants.product_id', 'orders.paid_at', 'order_items.quantity'])
            ->chunkById(500, function ($items) use (&$monthlyUnits) {
                foreach ($items as $item) {
                    $month = CarbonImmutable::parse($item->paid_at, 'Asia/Manila')->format('Y-m');
                    $monthlyUnits[$item->product_id][$month] = ($monthlyUnits[$item->product_id][$month] ?? 0) + $item->quantity;
                }
            }, 'order_items.id', 'id');

        return Product::with('variants')->where('active', true)->get()->map(function ($product) use ($end, $firstSales, $monthlyUnits) {
            $firstSale = isset($firstSales[$product->id]) ? CarbonImmutable::parse($firstSales[$product->id], 'Asia/Manila')->startOfMonth() : null;
            $created = $product->created_at ? CarbonImmutable::instance($product->created_at)->setTimezone('Asia/Manila')->startOfMonth() : $end;
            // Imported orders may legitimately predate the product's local creation date.
            $start = $firstSale && $firstSale->lt($created) ? $firstSale : $created;
            $months = $this->completedPeriods($start, $end);
            $totals = [];
            foreach ($months as $month) {
                $totals[$month] = $monthlyUnits[$product->id][$month] ?? 0;
            }
            $values = array_values($totals);
            // Time in the catalog alone cannot establish observed product demand.
            $summary = $this->summarize($values, $months, true, $firstSale !== null, $firstSale?->format('Y-m'));
            $prediction = $summary['regression']['prediction'];
            $available = $product->variants->sum(fn ($v) => $v->available());

            return $summary + ['name' => $product->name, 'sold' => array_sum($values), 'observed_history' => $firstSale !== null,
                'history_basis' => $firstSale && $firstSale->lt($created) ? 'Earlier verified paid sales' : 'Product added to the catalog',
                'forecast' => $prediction === null ? null : (int) ceil($prediction), 'available' => $available,
                'reorder' => $prediction === null ? null : max(0, (int) ceil($prediction) - $available)];
        })->all();
    }
}
