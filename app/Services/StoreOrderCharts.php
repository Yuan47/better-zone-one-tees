<?php

namespace App\Services;

use App\Models\OrderItem;
use Carbon\CarbonImmutable;

class StoreOrderCharts
{
    public function snapshot(): array
    {
        $now = CarbonImmutable::now('Asia/Manila');
        $start = $now->startOfMonth()->subMonths(3);
        $paid = app(SalesReport::class)->paidOrders($start->toDateString(), $now->toDateString());
        $channels = [];
        $channelCounts = (clone $paid)->selectRaw('sales_channel, COUNT(*) AS orders_count')->groupBy('sales_channel')->pluck('orders_count', 'sales_channel');
        $channelTotal = (int) $channelCounts->only(['walk_in', 'online'])->sum();
        foreach (['walk_in' => ['Walk-in checkout', '#941b0c'], 'online' => ['Online orders', '#f6aa1c']] as $key => [$label, $color]) {
            $count = (int) $channelCounts->get($key, 0);
            $channels[] = ['channel' => $key, 'label' => $label, 'color' => $color, 'count' => $count,
                'percentage' => $channelTotal ? round($count / $channelTotal * 100, 2) : 0];
        }
        $items = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('variants', 'variants.id', '=', 'order_items.variant_id')->join('products', 'products.id', '=', 'variants.product_id')
            ->whereIn('order_items.order_id', (clone $paid)->select('orders.id'));
        $leaders = (clone $items)->selectRaw('products.id AS product_id, products.name, SUM(order_items.quantity) AS units')
            ->groupBy('products.id', 'products.name')->orderByDesc('units')->orderBy('products.id')->limit(5)->get();
        $months = [];
        for ($i = 0; $i < 4; $i++) {
            $date = $start->addMonths($i);
            $months[] = ['key' => $date->format('Y-m'), 'label' => $date->format('M Y'), 'current' => $date->isSameMonth($now)];
        }
        $counts = [];
        if ($leaders->isNotEmpty()) {
            foreach ((clone $items)->whereIn('products.id', $leaders->pluck('product_id'))
                ->get(['products.id AS product_id', 'orders.paid_at', 'order_items.quantity']) as $item) {
                $month = CarbonImmutable::parse($item->paid_at, 'Asia/Manila')->format('Y-m');
                $counts[$item->product_id][$month] = ($counts[$item->product_id][$month] ?? 0) + (int) $item->quantity;
            }
        }
        $series = [];
        $colors = ['#941b0c', '#267478', '#b67a0b', '#76529d', '#295b35'];
        $max = 1;
        foreach ($leaders as $index => $leader) {
            $values = array_map(fn ($month) => $counts[$leader->product_id][$month['key']] ?? 0, $months);
            $max = max($max, ...$values);
            $series[] = ['product_id' => (int) $leader->product_id, 'name' => $leader->name, 'total' => (int) $leader->units,
                'values' => $values, 'color' => $colors[$index]];
        }
        $ceiling = max(4, (int) ceil($max / 4) * 4);
        $ticks = [];
        for ($i = 0; $i <= 4; $i++) {
            $ticks[] = ['value' => $ceiling / 4 * $i, 'y' => 230 - 180 * $i / 4];
        }
        foreach ($series as &$line) {
            $line['points'] = array_map(fn ($value, $index) => ['x' => 90 + 210 * $index, 'y' => round(230 - $value / $ceiling * 180, 2), 'value' => $value], $line['values'], array_keys($line['values']));
            $line['polyline'] = implode(' ', array_map(fn ($point) => $point['x'].','.$point['y'], $line['points']));
        }
        unset($line);

        return ['start_date' => $start->toDateString(), 'end_date' => $now->toDateString(),
            'range' => $start->format('M j').' – '.$now->format('M j, Y'), 'months' => $months,
            'channels' => $channels, 'channel_total' => $channelTotal, 'series' => $series, 'ticks' => $ticks];
    }
}
