<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Forecast;
use App\Services\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportsController extends Controller
{
    public function dashboard(Request $request, SalesReport $report)
    {
        $data = $report->filters($request->only(['period', 'start_date', 'end_date']));
        $period = $data['period'] ?? 'daily';
        $startDate = $data['start_date'];
        $endDate = $data['end_date'];
        $salesChart = $report->chart($period, $startDate, $endDate);
        $chart = $salesChart['rows'];
        $paidOrders = $report->paidOrders($startDate, $endDate);
        $revenue = (int) (clone $paidOrders)->sum('subtotal');
        $ordersCount = (clone $paidOrders)->count();
        $invoiceSummary = $report->invoiceSummary($startDate, $endDate);
        $salesScope = $salesChart['custom_range'] ? $salesChart['history'] : 'All time';
        $customers = User::where('role', 'customer')->count();
        $toPack = Order::whereDeliveryStage('packing')->count();
        $attention = $this->inventoryQuery();
        $lowStockCount = (clone $attention)->count();
        $lowStock = $this->urgentFirst($attention)->limit(6)->get();
        $best = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_items.variant_id')
            ->leftJoin('products', 'products.id', '=', 'variants.product_id')
            ->whereIn('order_items.order_id', (clone $paidOrders)->select('orders.id'))
            ->selectRaw('products.id AS product_id, COALESCE(products.name, order_items.name) AS name, SUM(order_items.quantity) AS units')
            ->groupBy('products.id')->groupByRaw('COALESCE(products.name, order_items.name)')
            ->orderByDesc('units')->orderBy('name')->orderBy('product_id')->limit(5)->get();
        $recent = Order::with('user')->latest()->limit(5)->get();

        // Operational cards show the current store position, independently of
        // the selected sales-chart period. Monthly sales retain paid-date scope.
        $now = CarbonImmutable::now('Asia/Manila');
        $activeInvoices = Order::where('status', '!=', 'cancelled')->where('inventory_released', false)
            ->where('created_at', '<=', $now->toDateTimeString());
        $openInvoices = (clone $activeInvoices)->whereIn('payment_status', ['pending', 'partially_paid'])
            ->where('total', '>', 0)->whereColumn('total', '>', 'amount_paid');
        $monthStart = $now->startOfMonth()->toDateString();
        $widgetStats = [
            'on_delivery' => (clone $activeInvoices)->whereDeliveryStage('courier')->count(),
            'outstanding_customers' => (clone $openInvoices)->whereHas('user', fn ($query) => $query->where('role', 'customer'))
                ->distinct()->count('user_id'),
            'guest_outstanding_invoices' => (clone $openInvoices)->whereNull('user_id')->count(),
            'outstanding_invoices' => (clone $openInvoices)->count(),
            'outstanding_payment' => (int) (clone $openInvoices)->sum(DB::raw('CASE WHEN amount_paid < 0 THEN total ELSE total - amount_paid END')),
            'month_sales' => (int) $report->paidOrders($monthStart, $now->toDateString())->sum('subtotal'),
            'month_label' => $now->format('F Y'),
            'as_of' => $now->toIso8601String(),
        ];
        $widgetLinks = [
            'on_delivery' => route('admin.orders', ['fulfillment' => 'courier', 'exclude_cancelled' => 1]),
            'outstanding' => route('admin.orders', ['outstanding' => 1, 'exclude_cancelled' => 1]),
            'outstanding_customers' => route('admin.orders', ['outstanding' => 1, 'exclude_cancelled' => 1]),
            'outstanding_payment' => route('admin.orders', ['outstanding' => 1, 'exclude_cancelled' => 1]),
            'month_sales' => route('admin.dashboard', ['period' => 'daily', 'start_date' => $monthStart, 'end_date' => $now->toDateString()]).'#reports',
        ];

        // Unpaid invoices have no paid_at. These two count charts use the
        // placed-order cohort, while the sales cards above retain paid_at.
        $invoiceFilters = $startDate ? ['start_date' => $startDate, 'end_date' => $endDate] : [];
        $invoiceScope = $startDate ? $salesChart['history'] : 'All time';
        $invoiceCounts = Order::where('status', '!=', 'cancelled')->where('inventory_released', false)
            ->where('created_at', '<=', CarbonImmutable::now('Asia/Manila')->toDateTimeString())
            ->when($startDate, fn ($query) => $query->where('created_at', '>=', $startDate.' 00:00:00')
                ->where('created_at', '<', CarbonImmutable::parse($endDate, 'Asia/Manila')->addDay()->toDateTimeString()))
            ->selectRaw('payment_status, delivery_method, COUNT(*) AS invoice_count')
            ->groupBy('payment_status', 'delivery_method')->get();
        $paymentTotal = 0;
        $paymentRows = [];
        foreach (['paid' => ['Fully paid', '#621708'], 'partially_paid' => ['Partially paid', '#BC3908'], 'pending' => ['Unpaid', '#F6AA1C']] as $status => [$label, $color]) {
            $count = (int) $invoiceCounts->where('payment_status', $status)->sum('invoice_count');
            $paymentTotal += $count;
            $paymentRows[] = ['status' => $status, 'label' => $label, 'count' => $count, 'color' => $color,
                'url' => route('admin.orders', $invoiceFilters + ['exclude_cancelled' => 1, 'payment_status' => $status])];
        }
        foreach ($paymentRows as &$row) {
            $row['percentage'] = $paymentTotal ? $row['count'] / $paymentTotal * 100 : 0;
        }
        unset($row);
        $otherPaymentCount = (int) $invoiceCounts->whereNotIn('payment_status', ['paid', 'partially_paid', 'pending'])->sum('invoice_count');
        $methodRows = [];
        $methodTotal = 0;
        foreach (['pickup' => ['Store pickup', '#941B0C'], 'lalamove' => ['Lalamove', '#F6AA1C']] as $method => [$label, $color]) {
            $count = (int) $invoiceCounts->where('payment_status', 'paid')->where('delivery_method', $method)->sum('invoice_count');
            $methodTotal += $count;
            $methodRows[] = ['method' => $method, 'label' => $label, 'count' => $count, 'color' => $color,
                'url' => route('admin.orders', $invoiceFilters + ['exclude_cancelled' => 1, 'payment_status' => 'paid', 'delivery_method' => $method])];
        }
        foreach ($methodRows as &$row) {
            $row['percentage'] = $methodTotal ? $row['count'] / $methodTotal * 100 : 0;
        }
        unset($row);
        $otherMethodCount = (int) $invoiceCounts->where('payment_status', 'paid')->whereNotIn('delivery_method', ['pickup', 'lalamove'])->sum('invoice_count');

        return view('admin.dashboard', compact('revenue', 'ordersCount', 'invoiceSummary', 'customers', 'toPack', 'lowStock', 'lowStockCount', 'chart', 'best', 'recent', 'period', 'salesChart', 'salesScope',
            'invoiceFilters', 'invoiceScope', 'paymentRows', 'paymentTotal', 'otherPaymentCount', 'methodRows', 'methodTotal', 'otherMethodCount', 'widgetStats', 'widgetLinks'));
    }

    public function forecast(Forecast $forecast)
    {
        return view('admin.forecast', ['forecasts' => $forecast->rows(), 'revenueForecast' => $forecast->revenue()]);
    }

    public function inventoryAttention(Request $request)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:100',
            'product' => ['nullable', 'integer', Rule::exists('products', 'id')->where('active', true)],
            'availability' => ['nullable', Rule::in(['all', 'out_of_stock'])],
        ]);
        $query = $this->inventoryQuery();
        if (! empty($filters['q'])) {
            $search = '%'.$filters['q'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', $search)->orWhere('size', 'like', $search)->orWhere('color', 'like', $search)
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', $search));
            });
        }
        if (! empty($filters['product'])) {
            $query->where('product_id', $filters['product']);
        }
        if (($filters['availability'] ?? 'all') === 'out_of_stock') {
            $query->whereRaw('stock - reserved <= 0');
        }
        $shortages = $this->urgentFirst($query)->paginate(30)->withQueryString();
        $products = Product::where('active', true)->orderBy('name')->get(['id', 'name']);

        return view('admin.inventory-attention', compact('shortages', 'products', 'filters'));
    }

    private function inventoryQuery(): Builder
    {
        return Variant::with('product')->whereHas('product', fn ($q) => $q->where('active', true))
            ->whereRaw('stock - reserved <= reorder_level');
    }

    private function urgentFirst(Builder $query): Builder
    {
        return $query->orderByRaw('stock - reserved ASC')
            ->orderByRaw('reorder_level - (stock - reserved) DESC')->orderBy('id');
    }
}
