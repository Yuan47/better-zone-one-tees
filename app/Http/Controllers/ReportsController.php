<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Models\Variant;
use App\Services\Forecast;
use App\Services\InventoryWarnings;
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
        $now = CarbonImmutable::now('Asia/Manila');
        // Summary cards default to the current calendar year. Chart grouping
        // keeps its own daily/weekly/monthly/yearly window until dates are set.
        $summaryStart = $startDate ?? $now->startOfYear()->toDateString();
        $summaryEnd = $endDate ?? $now->toDateString();
        $paidOrders = $report->paidOrders($summaryStart, $summaryEnd);
        $revenue = (int) (clone $paidOrders)->sum('subtotal');
        $ordersCount = (clone $paidOrders)->count();
        $invoiceSummary = $report->invoiceSummary($summaryStart, $summaryEnd);
        $salesScope = $salesChart['custom_range'] ? $salesChart['history'] : 'Annual Sales · '.$now->format('Y');
        $salesExportFilters = ['period' => $salesChart['custom_range'] ? $period : 'yearly',
            'start_date' => $summaryStart, 'end_date' => $summaryEnd];
        $customers = User::where('role', 'customer')->count();
        $attention = $this->inventoryQuery();
        $inventoryWarnings = app(InventoryWarnings::class)->counts();
        $lowStockCount = array_sum($inventoryWarnings);
        $lowStock = $this->urgentFirst($attention)->limit(6)->get();
        $best = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_items.variant_id')
            ->leftJoin('products', 'products.id', '=', 'variants.product_id')
            ->whereIn('order_items.order_id', (clone $paidOrders)->select('orders.id'))
            ->selectRaw('products.id AS product_id, COALESCE(products.name, order_items.name) AS name, SUM(order_items.quantity) AS units')
            ->groupBy('products.id')->groupByRaw('COALESCE(products.name, order_items.name)')
            ->orderByDesc('units')->orderBy('name')->orderBy('product_id')->limit(5)->get();
        $recent = Order::with('user')->latest()->limit(5)->get();
        $orderCharts = app(\App\Services\StoreOrderCharts::class)->snapshot();

        // Operational cards show the current store position, independently of
        // the selected sales-chart period. Monthly sales retain paid-date scope.
        $activeInvoices = Order::where('status', '!=', 'cancelled')->where('inventory_released', false)
            ->where('created_at', '<=', $now->toDateTimeString());
        $toPack = (clone $activeInvoices)->whereDeliveryStage('packing')->count();
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
        $taskCards = [
            ['label' => 'For packaging', 'count' => $toPack, 'url' => route('admin.orders', ['fulfillment' => 'packing', 'exclude_cancelled' => 1]), 'tone' => 'packing'],
            ['label' => 'Ready for delivery', 'count' => (clone $activeInvoices)->whereDeliveryStage('ready')->count(), 'url' => route('admin.orders', ['fulfillment' => 'ready', 'exclude_cancelled' => 1]), 'tone' => 'ready'],
            ['label' => 'Returns to review', 'count' => Refund::where('status', 'requested')->where('created_at', '<=', $now)->count(), 'url' => route('admin.refunds.index', ['status' => 'requested']), 'tone' => 'returns'],
            ['label' => 'Low-stock variants', 'count' => $lowStockCount, 'url' => route('admin.inventory-attention'), 'tone' => 'stock'],
        ];

        return $this->reportPage($request, 'admin.dashboard', compact('revenue', 'ordersCount', 'invoiceSummary', 'customers', 'toPack', 'lowStock', 'lowStockCount', 'inventoryWarnings', 'chart', 'best', 'recent', 'period', 'salesChart', 'salesScope', 'salesExportFilters',
            'widgetStats', 'widgetLinks', 'taskCards', 'orderCharts'));
    }

    public function forecast(Request $request, Forecast $forecast, SalesReport $report)
    {
        $filters = $report->filters($request->only(['period', 'start_date', 'end_date']));
        $period = $filters['period'];
        $salesChart = $report->chart($period, $filters['start_date'], $filters['end_date']);

        return $this->reportPage($request, 'admin.forecast', [
            'forecasts' => $forecast->rows(), 'revenueForecast' => $forecast->revenue(),
            'period' => $period, 'salesChart' => $salesChart, 'chart' => $salesChart['rows'],
        ]);
    }

    private function reportPage(Request $request, string $view, array $data)
    {
        $data['reportUpdatedAt'] = CarbonImmutable::now('Asia/Manila');
        $data['reportRefreshUrl'] = route($request->routeIs('admin.forecast') ? 'admin.forecast' : 'admin.dashboard', array_filter([
            'period' => $data['period'], 'start_date' => $data['salesChart']['start_date'], 'end_date' => $data['salesChart']['end_date'],
        ], static fn ($value) => $value !== null));

        // Refreshes use the same authenticated, verified role checks and sales
        // calculation as navigation. They cannot confirm a payment themselves.
        if ($request->expectsJson() && $request->header('X-Report-Refresh') === '1') {
            return response()->json(['html' => view($view, $data)->render(),
                'checked_at' => $data['reportUpdatedAt']->toIso8601String()])
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->view($view, $data)->header('Cache-Control', 'no-store, private');
    }

    public function inventoryAttention(Request $request)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:100',
            'product' => ['nullable', 'integer', Rule::exists('products', 'id')->where('active', true)->whereNull('archived_at')],
            'availability' => ['nullable', Rule::in(['all', 'out_of_stock'])],
        ]);
        $query = $this->inventoryQuery();
        if (filled($filters['q'] ?? null)) {
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
        $products = Product::published()->orderBy('name')->get(['id', 'name']);

        return view('admin.inventory-attention', compact('shortages', 'products', 'filters'));
    }

    private function inventoryQuery(): Builder
    {
        return Variant::sellable()->with('product')->whereHas('product', fn ($q) => $q->published())
            ->whereRaw('stock - reserved <= reorder_level');
    }

    private function urgentFirst(Builder $query): Builder
    {
        return $query->orderByRaw('stock - reserved ASC')
            ->orderByRaw('reorder_level - (stock - reserved) DESC')->orderBy('id');
    }
}
