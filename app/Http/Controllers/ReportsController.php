<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Forecast;
use App\Services\SalesReport;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
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
        $salesScope = $salesChart['custom_range'] ? $salesChart['history'] : 'All time';
        $customers = User::where('role', 'customer')->count();
        $toPack = Order::where('status', 'to_pack')->count();
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

        return view('admin.dashboard', compact('revenue', 'ordersCount', 'customers', 'toPack', 'lowStock', 'lowStockCount', 'chart', 'best', 'recent', 'period', 'salesChart', 'salesScope'));
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
