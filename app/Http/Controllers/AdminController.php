<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Forecast;
use App\Services\Lalamove;
use App\Services\PayMongo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function dashboard(Forecast $forecast)
    {
        $paid = Order::with('items.variant.product')->where('payment_status', 'paid')->get();
        $revenue = $paid->sum('subtotal');
        $ordersCount = $paid->count();
        $customers = User::where('role', 'customer')->count();
        $toPack = Order::where('status', 'to_pack')->count();
        $lowStock = Variant::with('product')->whereRaw('stock - reserved <= reorder_level')->limit(6)->get();
        $chart = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $chart[] = ['label' => $date->format('D'), 'amount' => $paid->filter(fn ($o) => $o->paid_at->isSameDay($date))->sum('subtotal')];
        }
        $best = $paid->flatMap->items->groupBy('name')->map(fn ($items) => $items->sum('quantity'))->sortDesc()->take(5);
        $category = collect();
        foreach ($paid as $order) {
            $allocated = 0;
            $items = $order->items->values();
            foreach ($items as $i => $item) {
                $net = $i === $items->count() - 1 ? $order->subtotal - $allocated : (int) round($item->total / max(1, $order->merchandise_base) * $order->subtotal);
                $allocated += $net;
                $name = $item->variant->product->category;
                $category[$name] = ($category[$name] ?? 0) + $net;
            }
        }
        $category = $category->sortDesc();
        $recent = Order::with('user')->latest()->limit(5)->get();
        $forecasts = $forecast->rows();
        $revenueForecast = $forecast->revenue();
        $categoryForecast = $forecast->categories();

        return view('admin.dashboard', compact('revenue', 'ordersCount', 'customers', 'toPack', 'lowStock', 'chart', 'best', 'category', 'recent', 'forecasts', 'revenueForecast', 'categoryForecast'));
    }

    public function products(Request $r)
    {
        $products = Product::with('variants')->when($r->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$r->string('q').'%'))->get();

        return view('admin.products', compact('products'));
    }

    public function edit(Product $product)
    {
        $product->load('variants');

        return view('admin.product', compact('product'));
    }

    public function createForm()
    {
        $product = new Product(['active' => true]);

        return view('admin.product', compact('product'));
    }

    public function save(Request $r, ?Product $product = null)
    {
        $data = $r->validate(['name' => 'required|string|max:150', 'category' => 'required|string|max:100', 'description' => 'required|string|max:2000',
            'art_color' => 'required|regex:/^#[0-9a-fA-F]{6}$/', 'active' => 'required|boolean',
            'image_url' => 'nullable|url:https|max:1000']);
        if (! $product) {
            $product = Product::create($data + ['slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5))]);
        } else {
            $product->update($data);
        }

        return redirect()->route('admin.products.edit', $product)->with('notice', 'Product saved. Add or update its size and color variants below.');
    }

    public function variant(Request $r, Product $product, ?Variant $variant = null)
    {
        if ($variant) {
            abort_unless($variant->product_id === $product->id, 404);
        }
        $data = $r->validate(['sku' => 'required|string|max:100|unique:variants,sku,'.($variant?->id ?? 'NULL'),
            'size' => 'required|string|max:40', 'color' => 'required|string|max:80', 'stock' => 'required|integer|min:0|max:1000000',
            'reorder_level' => 'required|integer|min:0|max:1000000',
            'retail' => 'required|numeric|min:1|max:100000', 'wholesale' => 'required|numeric|min:1|max:100000',
            'reseller' => 'required|numeric|min:1|max:100000']);
        foreach (['retail', 'wholesale', 'reseller'] as $tier) {
            $data[$tier.'_price'] = (int) round($data[$tier] * 100);
            unset($data[$tier]);
        }
        if ($data['wholesale_price'] > $data['retail_price'] || $data['reseller_price'] > $data['wholesale_price']) {
            return back()->withErrors(['prices' => 'Prices must stay the same or decrease as quantity increases.'])->withInput();
        }
        DB::transaction(function () use ($product, $variant, $data) {
            if ($variant) {
                $locked = Variant::whereKey($variant->id)->lockForUpdate()->firstOrFail();
                if ($data['stock'] < $locked->reserved) {
                    throw ValidationException::withMessages(['stock' => 'Stock cannot be lower than reserved quantity.']);
                }
                $locked->update($data);
            } else {
                $product->variants()->create($data);
            }
        });

        return back()->with('notice', 'Variant saved.');
    }

    public function orders(Request $r)
    {
        $orders = Order::with('user')->when($r->filled('status'), fn ($q) => $q->where('status', $r->string('status')))->latest()->paginate(20);

        return view('admin.orders', compact('orders'));
    }

    public function status(Request $r, Order $order)
    {
        $data = $r->validate(['status' => 'required|in:packing,ready,completed']);
        DB::transaction(function () use ($order, $data) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $allowed = ['to_pack' => ['packing'], 'packing' => ['ready'], 'ready' => $locked->delivery_method === 'pickup' ? ['completed'] : []];
            abort_unless($locked->payment_status === 'paid' && in_array($data['status'], $allowed[$locked->status] ?? []), 422);
            $locked->update($data);
        });

        return back()->with('notice', 'Order updated.');
    }

    public function book(Order $order, Lalamove $delivery)
    {
        if (! $delivery->ready()) {
            return back()->withErrors(['delivery' => 'Configure Lalamove sandbox credentials and pickup location first.']);
        }
        try {
            // Lock stops two staff members booking the same order concurrently.
            DB::transaction(function () use ($order) {
                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_unless($order->status === 'ready' && $order->payment_status === 'paid' && $order->delivery_method === 'lalamove' && ! $order->delivery_id, 422);
                $order->update(['status' => 'delivery_booking']);
            });
            $result = $delivery->book($order);
            $order->update(['delivery_id' => $result['orderId'], 'tracking_url' => $result['shareLink'] ?? null, 'status' => 'out_for_delivery']);
        } catch (ValidationException $e) {
            $order->update(['status' => 'ready']);
            throw $e;
        } catch (\Throwable $e) {
            return back()->withErrors(['delivery' => 'Booking outcome needs checking in Lalamove before retrying. Staff should reconcile this order.']);
        }

        return back()->with('notice', 'Delivery booked.');
    }

    public function customers()
    {
        $customers = User::where('role', 'customer')->latest()->paginate(20);

        return view('admin.customers', compact('customers'));
    }

    public function export()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Order', 'Paid at', 'Pieces', 'Pricing tier', 'Clothing base PHP', 'Bulk discount PHP', 'Net clothing PHP', 'Delivery PHP', 'Total PHP'], ',', '"', '');
            Order::where('payment_status', 'paid')->orderBy('id')->chunk(100, function ($orders) use ($out) {
                foreach ($orders as $o) {
                    fputcsv($out, [$o->number, $o->paid_at?->toIso8601String(), $o->quantity, $o->tier,
                        number_format($o->merchandise_base / 100, 2, '.', ''), number_format($o->discount / 100, 2, '.', ''), number_format($o->subtotal / 100, 2, '.', ''),
                        number_format($o->shipping / 100, 2, '.', ''), number_format($o->total / 100, 2, '.', '')], ',', '"', '');
                }
            });
            fclose($out);
        }, 'zone-one-sales-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function settings(PayMongo $pay, Lalamove $delivery)
    {
        $faq = DB::table('shop_settings')->where('key', 'chatbot_faq')->value('value') ?? '';

        return view('admin.settings', ['faq' => $faq, 'payReady' => $pay->ready(), 'deliveryReady' => $delivery->ready(), 'grokReady' => filled(config('shop.grok.key'))]);
    }

    public function saveSettings(Request $r)
    {
        $data = $r->validate(['faq' => 'nullable|string|max:5000']);
        DB::table('shop_settings')->updateOrInsert(['key' => 'chatbot_faq'], ['value' => $data['faq'] ?? '']);

        return back()->with('notice', 'Chatbot store guidance saved.');
    }
}
