<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsController extends Controller
{
    public const STATUSES = [
        'to_pack' => 'To pack', 'packing' => 'Packing', 'ready' => 'Ready for handover',
        'delivery_booking' => 'Delivery booking', 'delivery_attention' => 'Delivery needs attention',
        'out_for_delivery' => 'Out for delivery', 'completed' => 'Completed',
    ];

    public function index(Request $request)
    {
        $now = CarbonImmutable::now('Asia/Manila');
        $today = $now->format('Y-m-d');
        $data = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'period' => ['nullable', Rule::in(['daily', 'monthly'])],
        ]);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $data['end_date'] ?? $today, 'Asia/Manila');
        $start = isset($data['start_date']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['start_date'], 'Asia/Manila') : $end->subDays(29);
        if ($start->gt($end)) {
            throw ValidationException::withMessages(['end_date' => 'The end date must be on or after the start date.']);
        }
        $days = (int) $start->diffInDays($end) + 1;
        if ($days > 366) {
            throw ValidationException::withMessages(['end_date' => 'Choose a date range of 366 days or less.']);
        }
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        $endExclusive = $end->addDay()->format('Y-m-d H:i:s');
        $from = $start->format('Y-m-d H:i:s');
        $asOf = $now->format('Y-m-d H:i:s');
        $period = $data['period'] ?? ($days > 62 ? 'monthly' : 'daily');
        $chart = $this->buckets($start, $end, $period);
        $lookup = array_flip(array_column($chart, 'key'));
        $stats = ['placed_orders' => 0, 'paid_orders' => 0, 'paid_pieces' => 0, 'pickup' => 0, 'lalamove' => 0, 'other_delivery' => 0,
            'unpaid_pending' => 0, 'cancelled_unpaid' => 0, 'other_unpaid' => 0, 'confirmed_refunds' => 0, 'refund_amount' => 0, 'open_refund_requests' => 0];
        $statusCounts = array_fill_keys(array_keys(self::STATUSES), 0) + ['other' => 0];
        $deliveryCounts = $statusCounts;
        Order::where('created_at', '>=', $from)->where('created_at', '<', $endExclusive)->where('created_at', '<=', $asOf)
            ->select(['id', 'created_at', 'payment_status', 'status', 'delivery_method', 'quantity'])
            ->chunkById(500, function ($orders) use (&$stats, &$statusCounts, &$deliveryCounts, &$chart, $lookup, $period) {
                foreach ($orders as $order) {
                    $stats['placed_orders']++;
                    if ($order->payment_status !== 'paid') {
                        $unpaid = $order->status === 'cancelled' ? 'cancelled_unpaid' : ($order->payment_status === 'pending' ? 'unpaid_pending' : 'other_unpaid');
                        $stats[$unpaid]++;

                        continue;
                    }
                    $stats['paid_orders']++;
                    $stats['paid_pieces'] += $order->quantity;
                    $method = in_array($order->delivery_method, ['pickup', 'lalamove'], true) ? $order->delivery_method : 'other_delivery';
                    $stats[$method]++;
                    $status = array_key_exists($order->status, self::STATUSES) ? $order->status : 'other';
                    $statusCounts[$status]++;
                    if ($method === 'lalamove') {
                        $deliveryCounts[$status]++;
                    }
                    $key = CarbonImmutable::instance($order->created_at)->setTimezone('Asia/Manila')->format($period === 'daily' ? 'Y-m-d' : 'Y-m');
                    $chart[$lookup[$key]]['orders']++;
                }
            });
        Refund::where('status', 'succeeded')->whereNotNull('provider_refund_id')->where('provider_refund_id', '<>', '')->where('amount', '>', 0)
            ->where('confirmed_at', '>=', $from)->where('confirmed_at', '<', $endExclusive)->where('confirmed_at', '<=', $asOf)
            ->select(['id', 'confirmed_at', 'amount'])
            ->chunkById(500, function ($refunds) use (&$stats, &$chart, $lookup, $period) {
                foreach ($refunds as $refund) {
                    $stats['confirmed_refunds']++;
                    $stats['refund_amount'] += $refund->amount;
                    $key = CarbonImmutable::instance($refund->confirmed_at)->setTimezone('Asia/Manila')->format($period === 'daily' ? 'Y-m-d' : 'Y-m');
                    $chart[$lookup[$key]]['refunds']++;
                    $chart[$lookup[$key]]['refund_amount'] += $refund->amount;
                }
            });
        $stats['open_refund_requests'] = Refund::whereIn('status', ['requested', 'approved', 'pending', 'processing'])
            ->where('created_at', '>=', $from)->where('created_at', '<', $endExclusive)->where('created_at', '<=', $asOf)->count();
        $filters = ['start_date' => $startDate, 'end_date' => $endDate];
        $statusRows = [];
        foreach (self::STATUSES + ['other' => 'Other paid statuses'] as $status => $label) {
            $statusRows[] = ['status' => $status, 'label' => $label, 'count' => $statusCounts[$status],
                'percentage' => $stats['paid_orders'] ? $statusCounts[$status] / $stats['paid_orders'] * 100 : 0,
                'url' => $status === 'other' ? null : route('admin.orders', $filters + ['status' => $status, 'payment_status' => 'paid'])];
        }

        $deliveryStages = [
            'to_pack' => ['Waiting to pack', 'Payment is confirmed. Pack the clothing before arranging a courier.', '#220901'],
            'packing' => ['Being packed', 'The team is preparing the clothing. Mark the order ready when packing is complete.', '#621708'],
            'ready' => ['Ready for courier', 'Packing is complete. Arrange a courier booking for this order.', '#941B0C'],
            'delivery_booking' => ['Booking being checked', 'Check the existing Lalamove booking before trying to book again.', '#BC3908'],
            'delivery_attention' => ['Needs attention', 'Review this order and check its courier details before continuing.', '#F6AA1C'],
            'out_for_delivery' => ['With courier', 'A courier booking is active. Open the order to check its latest Lalamove status.', '#795548'],
            'completed' => ['Delivered', 'The order is marked delivered. Open it to review the delivery details.', '#b17b4a'],
            'other' => ['Other status', 'This order uses another status. Open it to decide the next step.', '#d8c3b8'],
        ];
        $deliveryRows = [];
        foreach ($deliveryStages as $status => [$label, $description, $color]) {
            $deliveryRows[] = ['status' => $status, 'label' => $label, 'description' => $description, 'color' => $color,
                'count' => $deliveryCounts[$status], 'percentage' => $stats['lalamove'] ? $deliveryCounts[$status] / $stats['lalamove'] * 100 : 0,
                'url' => route('admin.orders', $filters + ['status' => $status, 'payment_status' => 'paid', 'delivery_method' => 'lalamove'])];
        }
        $deliveryTotals = ['total' => $stats['lalamove'], 'completed' => $deliveryCounts['completed'],
            'active' => $stats['lalamove'] - $deliveryCounts['completed'],
            'attention' => $deliveryCounts['delivery_booking'] + $deliveryCounts['delivery_attention'], 'ready' => $deliveryCounts['ready']];

        return view('admin.operations', compact('stats', 'statusRows', 'deliveryRows', 'deliveryTotals', 'chart', 'startDate', 'endDate', 'period', 'now', 'today', 'filters'));
    }

    private function buckets(CarbonImmutable $start, CarbonImmutable $end, string $period): array
    {
        $rows = [];
        $cursor = $period === 'daily' ? $start : $start->startOfMonth();
        while ($cursor->lte($end)) {
            $next = $period === 'daily' ? $cursor->addDay() : $cursor->addMonth();
            $bucketStart = $cursor->lt($start) ? $start : $cursor;
            $bucketEnd = $next->subDay()->gt($end) ? $end : $next->subDay();
            $rows[] = ['key' => $cursor->format($period === 'daily' ? 'Y-m-d' : 'Y-m'), 'label' => $cursor->format($period === 'daily' ? 'M j' : 'M Y'),
                'range' => $bucketStart->format('M j, Y').($bucketStart->eq($bucketEnd) ? '' : ' – '.$bucketEnd->format('M j, Y')),
                'orders' => 0, 'refunds' => 0, 'refund_amount' => 0];
            $cursor = $next;
        }

        return $rows;
    }
}
