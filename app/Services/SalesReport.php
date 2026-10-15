<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SalesReport
{
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    public function filters(array $input): array
    {
        $today = CarbonImmutable::now('Asia/Manila')->format('Y-m-d');
        $data = Validator::make($input, [
            'period' => ['nullable', 'in:'.implode(',', self::PERIODS)],
            'start_date' => ['nullable', 'required_with:end_date', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'end_date' => ['nullable', 'required_with:start_date', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:'.$today],
        ])->validate();
        $period = $data['period'] ?? 'daily';
        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;
        if ($startDate !== null) {
            $start = CarbonImmutable::parse($startDate, 'Asia/Manila')->startOfDay();
            $end = CarbonImmutable::parse($endDate, 'Asia/Manila')->startOfDay();
            if (in_array($period, ['daily', 'weekly'], true) && $start->diffInDays($end) + 1 > 366) {
                throw ValidationException::withMessages(['end_date' => 'Daily and weekly ranges may cover at most 366 days.']);
            }
            if (in_array($period, ['monthly', 'yearly'], true) && $start->lt($end->subYears(10))) {
                throw ValidationException::withMessages(['end_date' => 'Monthly and yearly ranges may cover at most 10 years.']);
            }
            if ($this->bucketCount($this->bucketStart($start, $period), $this->bucketStart($end, $period), $period) > 120) {
                throw ValidationException::withMessages(['end_date' => 'Select at most 120 periods. Use a larger grouping or a shorter date range.']);
            }
        }

        return ['period' => $period, 'start_date' => $startDate, 'end_date' => $endDate];
    }

    public function paidOrders(?string $startDate = null, ?string $endDate = null): Builder
    {
        $query = Order::where('payment_status', 'paid');
        if ($startDate !== null && $endDate !== null) {
            $query->where('paid_at', '>=', CarbonImmutable::parse($startDate, 'Asia/Manila')->startOfDay()->format('Y-m-d H:i:s'))
                ->where('paid_at', '<', CarbonImmutable::parse($endDate, 'Asia/Manila')->addDay()->startOfDay()->format('Y-m-d H:i:s'))
                ->where('paid_at', '<=', CarbonImmutable::now('Asia/Manila')->format('Y-m-d H:i:s'));
        }

        return $query;
    }

    public function chart(string $period = 'daily', ?string $startDate = null, ?string $endDate = null): array
    {
        if (! in_array($period, self::PERIODS, true)) {
            throw new InvalidArgumentException('Unsupported sales period.');
        }

        $now = CarbonImmutable::now('Asia/Manila');
        $filters = $this->filters(['period' => $period, 'start_date' => $startDate, 'end_date' => $endDate]);
        $customRange = $filters['start_date'] !== null;
        [$start, $count, $step, $label, $history] = match ($period) {
            'daily' => [$now->startOfDay()->subDays(6), 7, 'day', 'M j', 'Last 7 days'],
            'weekly' => [$now->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(7), 8, 'week', 'M j', 'Last 8 weeks'],
            'monthly' => [$now->startOfMonth()->subMonths(11), 12, 'month', 'M y', 'Last 12 months'],
            'yearly' => [$now->startOfYear()->subYears(4), 5, 'year', 'Y', 'Last 5 years'],
        };
        $rangeStart = $start;
        $rangeEnd = $now->startOfDay();
        if ($customRange) {
            $rangeStart = CarbonImmutable::parse($startDate, 'Asia/Manila')->startOfDay();
            $rangeEnd = CarbonImmutable::parse($endDate, 'Asia/Manila')->startOfDay();
            $start = $this->bucketStart($rangeStart, $period);
            $count = $this->bucketCount($start, $this->bucketStart($rangeEnd, $period), $period);
            $history = $rangeStart->format('M j, Y').' – '.$rangeEnd->format('M j, Y');
        }

        $rows = [];
        $lookup = [];
        for ($i = 0; $i < $count; $i++) {
            $date = $start->add($step, $i);
            $end = $date->add($step, 1);
            $shownStart = $customRange && $date->lt($rangeStart) ? $rangeStart : $date;
            $shownEnd = $customRange && $end->subDay()->gt($rangeEnd) ? $rangeEnd : $end->subDay();
            $lookup[$this->key($date, $period)] = $i;
            $rows[] = [
                'label' => $date->format($label),
                'range' => $shownStart->format('M j, Y').' – '.$shownEnd->format('M j, Y'),
                'start' => $date->format('Y-m-d'),
                'amount' => 0,
                'orders' => 0,
                'current' => $date->lte($now) && $end->gt($now) && (! $customRange || $rangeEnd->isSameDay($now)),
            ];
        }

        // The app writes paid_at in its Asia/Manila timezone. Group verified
        // clothing subtotals, already net of discounts, without shipping.
        $this->paidOrders($rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'))
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
            'custom_range' => $customRange,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'today' => $now->format('Y-m-d'),
            'plot' => $this->plot($rows),
        ];
    }

    private function bucketStart(CarbonImmutable $date, string $period): CarbonImmutable
    {
        return match ($period) {
            'daily' => $date->startOfDay(),
            'weekly' => $date->startOfWeek(CarbonImmutable::MONDAY),
            'monthly' => $date->startOfMonth(),
            'yearly' => $date->startOfYear(),
        };
    }

    private function bucketCount(CarbonImmutable $start, CarbonImmutable $end, string $period): int
    {
        return match ($period) {
            'daily' => (int) $start->diffInDays($end) + 1,
            'weekly' => (int) ($start->diffInDays($end) / 7) + 1,
            'monthly' => ($end->year - $start->year) * 12 + $end->month - $start->month + 1,
            'yearly' => $end->year - $start->year + 1,
        };
    }

    private function plot(array $rows): array
    {
        $width = max(760, count($rows) * 44 + 130);
        $maximum = max(100, ...array_column($rows, 'amount'));
        $points = [];
        foreach ($rows as $i => $row) {
            $x = count($rows) === 1 ? $width / 2 : 100 + $i * ($width - 125) / (count($rows) - 1);
            $points[] = ['x' => round($x, 2), 'y' => round(210 - $row['amount'] / $maximum * 180, 2)];
        }
        $ticks = [];
        for ($i = 0; $i <= 4; $i++) {
            $ticks[] = ['y' => 210 - $i * 45, 'amount' => (int) round($maximum * $i / 4)];
        }

        return ['width' => $width, 'height' => 260, 'points' => $points, 'ticks' => $ticks,
            'line' => implode(' ', array_map(fn ($point) => $point['x'].','.$point['y'], $points))];
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
