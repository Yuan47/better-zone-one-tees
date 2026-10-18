<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (in_array($user->role, ['staff', 'admin'], true)) {
            return redirect()->route('admin.dashboard');
        }
        abort_unless($user->role === 'customer', 403);

        $ownedOrders = Order::where('user_id', $user->id);
        $orderCount = (clone $ownedOrders)->count();
        $recentOrders = (clone $ownedOrders)->with('items')->latest('id')->limit(4)->get();
        $openInvoices = (clone $ownedOrders)->where('status', '!=', 'cancelled')
            ->where('inventory_released', false)->whereIn('payment_status', ['pending', 'partially_paid'])
            ->whereColumn('total', '>', 'amount_paid');
        $openInvoiceCount = (clone $openInvoices)->count();
        $outstandingBalance = (int) $openInvoices->sum(DB::raw('total - amount_paid'));
        $favoriteCount = Product::where('active', true)->whereIn('id', DB::table('wishlists')
            ->where('user_id', $user->id)->select('product_id'))->count();

        return view('customer-dashboard', compact('user', 'orderCount', 'recentOrders', 'openInvoiceCount', 'outstandingBalance', 'favoriteCount'));
    }
}
