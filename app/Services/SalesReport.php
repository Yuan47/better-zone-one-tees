<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class SalesReport
{
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    public function chart(string $period = 'daily'): array
    {
        if (! in_array($period, self::PERIODS, true)) {
            throw new InvalidArgumentException('Unsupported sales period.');
        }

        $now = CarbonImmutable::now('Asia/Manila');
        [$start, $count, $step, $label, $history] = match ($period) {
            'daily' => [$now->startOfDay()->subDays(6), 7, 'day', 'M j', 'Last 7 days'],
            'weekly' => [$now->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(7), 8, 'week', 'M j', 'Last 8 weeks'],
            'monthly' => [$now->startOfMonth()->subMonths(11), 12, 'month', 'M y', 'Last 12 months'],
            'yearly' => [$now->startOfYear()->subYears(4), 5, 'year', 'Y', 'Last 5 years'],
        };

        $rows = [];
        $lookup = [];
        for ($i = 0; $i < $count; $i++) {
            $date = $start->add($step, $i);
            $end = $date->add($step, 1);
            $lookup[$this->key($date, $period)] = $i;
            $rows[] = [
                'label' => $date->format($label),
                'range' => $date->format('M j, Y').' – '.$end->subDay()->format('M j, Y'),
                'start' => $date->format('Y-m-d'),
                'amount' => 0,
                'orders' => 0,
                'current' => $i === $count - 1,
            ];
        }

        // The app writes paid_at in its Asia/Manila timezone. Group verified
        // clothing subtotals, already net of discounts, without shipping.
        Order::where('payment_status', 'paid')
            ->where('paid_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('paid_at', '<=', $now->format('Y-m-d H:i:s'))
            ->select(['id', 'paid_at', 'subtotal'])
            ->chunkById(500, function ($orders) use (&$rows, $lookup, $period) {
                foreach ($orders as $order) {
                    $key = $this->key(CarbonImmutable::instance($order->paid_at)->setTimezone('Asia/Manila'), $period);
                    if (array_key_exists($key, $lookup)) {
                        $index = $lookup[$key];
                        $rows[$index]['amount'] += $order->subtotal;
                        $rows[$index]['orders']++;
                    }
                }
            });

        return [
            'period' => $period,
            'history' => $history,
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'amount')),
            'orders' => array_sum(array_column($rows, 'orders')),
        ];
    }

    private function key(CarbonImmutable $date, string $period): string
    {
        return match ($period) {
            'daily' => $date->format('Y-m-d'),
            'weekly' => $date->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'),
            'monthly' => $date->format('Y-m'),
            'yearly' => $date->format('Y'),
        };
    }
}
