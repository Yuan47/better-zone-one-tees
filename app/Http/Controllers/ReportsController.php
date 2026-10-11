<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Variant;
use App\Services\Forecast;
use App\Services\SalesReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportsController extends Controller
{
    public function dashboard(Request $request, SalesReport $report)
    {
        $data = $request->validate(['period' => ['nullable', Rule::in(SalesReport::PERIODS)]]);
        $period = $data['period'] ?? 'daily';
        $salesChart = $report->chart($period);
        $chart = $salesChart['rows'];
        $revenue = (int) Order::where('payment_status', 'paid')->sum('subtotal');
        $ordersCount = Order::where('payment_status', 'paid')->count();
        $customers = User::where('role', 'customer')->count();
        $toPack = Order::where('status', 'to_pack')->count();
        $lowStock = Variant::with('product')->whereHas('product', fn ($q) => $q->where('active', true))
            ->whereRaw('stock - reserved <= reorder_level')->limit(6)->get();
        $best = OrderItem::whereIn('order_id', Order::select('id')->where('payment_status', 'paid'))
            ->selectRaw('name, SUM(quantity) AS units')->groupBy('name')->orderByDesc('units')->limit(5)
            ->get()->pluck('units', 'name');
        $recent = Order::with('user')->latest()->limit(5)->get();

        return view('admin.dashboard', compact('revenue', 'ordersCount', 'customers', 'toPack', 'lowStock', 'chart', 'best', 'recent', 'period', 'salesChart'));
    }

    public function forecast(Forecast $forecast)
    {
        return view('admin.forecast', ['forecasts' => $forecast->rows(), 'revenueForecast' => $forecast->revenue()]);
    }
}
