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
    public const STATUSES = Order::DELIVERY_STAGES;

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
            'fulfillment_orders' => 0, 'cancelled_paid' => 0, 'cancelled_paid_lalamove' => 0,
            'partially_paid' => 0, 'outstanding_invoices' => 0, 'outstanding_amount' => 0, 'received_deposits' => 0, 'overdue_invoices' => 0,
            'provider_refunds' => 0, 'provider_refund_amount' => 0, 'cash_refunds' => 0, 'cash_refund_amount' => 0,
            'unpaid_pending' => 0, 'cancelled_unpaid' => 0, 'other_unpaid' => 0, 'confirmed_refunds' => 0, 'refund_amount' => 0, 'open_refund_requests' => 0];
        $statusCounts = array_fill_keys(array_keys(self::STATUSES), 0);
        $deliveryCounts = $statusCounts;
        $deliveryAttention = 0;
        $paymentCounts = ['paid' => 0, 'partially_paid' => 0, 'pending' => 0];
        $methodCounts = ['pickup' => 0, 'lalamove' => 0];
        $otherPaymentCount = 0;
        $otherMethodCount = 0;
        Order::where('created_at', '>=', $from)->where('created_at', '<', $endExclusive)->where('created_at', '<=', $asOf)
            ->select(['id', 'created_at', 'payment_status', 'status', 'delivery_method', 'quantity', 'customer_confirmed_at',
                'sales_channel', 'walk_in_received_at', 'total', 'amount_paid', 'due_at', 'inventory_released'])
            ->chunkById(500, function ($orders) use (&$stats, &$statusCounts, &$deliveryCounts, &$deliveryAttention, &$paymentCounts, &$methodCounts, &$otherPaymentCount, &$otherMethodCount, &$chart, $lookup, $period, $now) {
                foreach ($orders as $order) {
                    $stats['placed_orders']++;
                    if ($order->status !== 'cancelled' && ! $order->inventory_released) {
                        if (array_key_exists($order->payment_status, $paymentCounts)) {
                            $paymentCounts[$order->payment_status]++;
                        } else {
                            $otherPaymentCount++;
                        }
                        if ($order->payment_status === 'paid') {
                            if (array_key_exists($order->delivery_method, $methodCounts)) {
                                $methodCounts[$order->delivery_method]++;
                            } else {
                                $otherMethodCount++;
                            }
                        }
                    }
                    if ($order->payment_status !== 'paid') {
                        $unpaid = $order->status === 'cancelled' ? 'cancelled_unpaid'
                            : match ($order->payment_status) {
                                'pending' => 'unpaid_pending', 'partially_paid' => 'partially_paid', default => 'other_unpaid',
                            };
                        $stats[$unpaid]++;
                        if ($order->status !== 'cancelled' && in_array($order->payment_status, ['pending', 'partially_paid'], true)) {
                            $balance = max(0, $order->total - $order->amount_paid);
                            if ($balance > 0) {
                                $stats['outstanding_invoices']++;
                                $stats['outstanding_amount'] += $balance;
                                if ($order->due_at && CarbonImmutable::parse($order->due_at, 'Asia/Manila')->lt($now)) {
                                    $stats['overdue_invoices']++;
                                }
                            }
                            if ($order->payment_status === 'partially_paid') {
                                $stats['received_deposits'] += $order->amount_paid;
                            }
                        }

                        continue;
                    }
                    $stats['paid_orders']++;
                    $stats['paid_pieces'] += $order->quantity;
                    $method = in_array($order->delivery_method, ['pickup', 'lalamove'], true) ? $order->delivery_method : 'other_delivery';
                    $stats[$method]++;
                    if ($order->status === 'cancelled') {
                        $stats['cancelled_paid']++;
                        $stats['cancelled_paid_lalamove'] += $method === 'lalamove' ? 1 : 0;
                    } else {
                        $stats['fulfillment_orders']++;
                        $stage = $order->customerDeliveryStage();
                        $statusCounts[$stage]++;
                        if ($method === 'lalamove') {
                            $deliveryCounts[$stage]++;
                            $deliveryAttention += in_array($order->status, ['delivery_booking', 'delivery_attention'], true) ? 1 : 0;
                        }
                    }
                    $key = CarbonImmutable::instance($order->created_at)->setTimezone('Asia/Manila')->format($period === 'daily' ? 'Y-m-d' : 'Y-m');
                    $chart[$lookup[$key]]['orders']++;
                }
            });
        Refund::where('status', 'succeeded')->where('amount', '>', 0)
            ->where(function ($query) {
                $query->where(fn ($provider) => $provider->whereNotNull('provider_refund_id')->where('provider_refund_id', '<>', ''))
                    ->orWhere(fn ($cash) => $cash->whereNull('provider_refund_id')->whereNotNull('cash_recorded_by')
                        ->whereNotNull('cash_reason')->where('cash_reason', '<>', '')
                        ->whereHas('paymentAttempt', fn ($payment) => $payment->where('method', 'cash')->where('status', 'paid')
                            ->whereNotNull('received_at')->whereColumn('payment_attempts.order_id', 'refunds.order_id')
                            ->whereColumn('payment_attempts.amount', '>=', 'refunds.amount'))
                        ->whereExists(fn ($audit) => $audit->selectRaw('1')->from('activity_logs')->where('target_type', 'refund')
                            ->whereColumn('target_id', 'refunds.id')->where('action', 'refund.cash_recorded')
                            ->where('actor_role', 'admin')->whereColumn('actor_id', 'refunds.cash_recorded_by')));
            })
            ->where('confirmed_at', '>=', $from)->where('confirmed_at', '<', $endExclusive)->where('confirmed_at', '<=', $asOf)
            ->select(['id', 'confirmed_at', 'amount', 'provider_refund_id'])
            ->chunkById(500, function ($refunds) use (&$stats, &$chart, $lookup, $period) {
                foreach ($refunds as $refund) {
                    $stats['confirmed_refunds']++;
                    $stats['refund_amount'] += $refund->amount;
                    $kind = filled($refund->provider_refund_id) ? 'provider' : 'cash';
                    $stats[$kind.'_refunds']++;
                    $stats[$kind.'_refund_amount'] += $refund->amount;
                    $key = CarbonImmutable::instance($refund->confirmed_at)->setTimezone('Asia/Manila')->format($period === 'daily' ? 'Y-m-d' : 'Y-m');
                    $chart[$lookup[$key]]['refunds']++;
                    $chart[$lookup[$key]]['refund_amount'] += $refund->amount;
                }
            });
        $stats['open_refund_requests'] = Refund::whereIn('status', ['requested', 'approved', 'pending', 'processing'])
            ->where('created_at', '>=', $from)->where('created_at', '<', $endExclusive)->where('created_at', '<=', $asOf)->count();
        $filters = ['start_date' => $startDate, 'end_date' => $endDate];
        $paymentTotal = array_sum($paymentCounts);
        $paymentRows = [];
        foreach (['paid' => ['Fully paid', '#621708'], 'partially_paid' => ['Partially paid', '#BC3908'], 'pending' => ['Unpaid', '#F6AA1C']] as $status => [$label, $color]) {
            $paymentRows[] = ['status' => $status, 'label' => $label, 'count' => $paymentCounts[$status], 'color' => $color,
                'percentage' => $paymentTotal ? $paymentCounts[$status] / $paymentTotal * 100 : 0,
                'url' => route('admin.orders', $filters + ['exclude_cancelled' => 1, 'payment_status' => $status])];
        }
        $methodTotal = array_sum($methodCounts);
        $methodRows = [];
        foreach (['pickup' => ['Store pickup', '#941B0C'], 'lalamove' => ['Lalamove', '#F6AA1C']] as $method => [$label, $color]) {
            $methodRows[] = ['method' => $method, 'label' => $label, 'count' => $methodCounts[$method], 'color' => $color,
                'percentage' => $methodTotal ? $methodCounts[$method] / $methodTotal * 100 : 0,
                'url' => route('admin.orders', $filters + ['exclude_cancelled' => 1, 'payment_status' => 'paid', 'delivery_method' => $method])];
        }
        $statusRows = [];
        foreach (self::STATUSES as $status => $label) {
            $statusRows[] = ['status' => $status, 'label' => $label, 'count' => $statusCounts[$status], 'color' => Order::DELIVERY_STAGE_COLORS[$status],
                'percentage' => $stats['fulfillment_orders'] ? $statusCounts[$status] / $stats['fulfillment_orders'] * 100 : 0,
                'url' => route('admin.orders', $filters + ['fulfillment' => $status, 'payment_status' => 'paid'])];
        }

        $deliveryStages = [
            'packing' => ['Packing', 'Payment is confirmed. Pack the clothing before arranging a courier.'],
            'ready' => ['Ready for delivery', 'Packing is complete. Arrange a courier booking for this order.'],
            'courier' => ['With courier', 'A courier booking is active. Open the order to check its latest Lalamove status.'],
            'delivered' => ['Delivered', 'The order is marked delivered. Open it to review the delivery details.'],
        ];
        $deliveryTotal = array_sum($deliveryCounts);
        $deliveryRows = [];
        foreach ($deliveryStages as $status => [$label, $description]) {
            $deliveryRows[] = ['status' => $status, 'label' => $label, 'description' => $description, 'color' => Order::DELIVERY_STAGE_COLORS[$status],
                'count' => $deliveryCounts[$status], 'percentage' => $deliveryTotal ? $deliveryCounts[$status] / $deliveryTotal * 100 : 0,
                'url' => route('admin.orders', $filters + ['fulfillment' => $status, 'payment_status' => 'paid', 'delivery_method' => 'lalamove'])];
        }
        $deliveryTotals = ['total' => $deliveryTotal, 'completed' => $deliveryCounts['delivered'],
            'active' => $deliveryTotal - $deliveryCounts['delivered'], 'attention' => $deliveryAttention, 'ready' => $deliveryCounts['ready']];

        return view('admin.operations', compact('stats', 'statusRows', 'deliveryRows', 'deliveryTotals', 'chart', 'startDate', 'endDate', 'period', 'now', 'today', 'filters',
            'paymentRows', 'paymentTotal', 'otherPaymentCount', 'methodRows', 'methodTotal', 'otherMethodCount'));
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
