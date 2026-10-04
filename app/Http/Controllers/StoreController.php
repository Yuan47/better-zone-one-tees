<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use App\Services\Lalamove;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use App\Services\Pricing;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreController extends Controller
{
    public function index(Request $r)
    {
        $query = Product::with('variants')->where('active', true);
        if ($r->filled('q')) {
            $query->where('name', 'like', '%'.mb_substr($r->string('q'), 0, 100).'%');
        }
        if ($r->filled('category')) {
            $query->where('category', $r->string('category'));
        }
        if ($r->boolean('wishlist')) {
            abort_unless($r->user(), 403);
            $query->whereIn('id', DB::table('wishlists')->where('user_id', $r->user()->id)->pluck('product_id'));
        }
        if ($r->filled('size')) {
            $query->whereHas('variants', fn ($q) => $q->where('size', $r->string('size')));
        }
        if ($r->filled('color')) {
            $query->whereHas('variants', fn ($q) => $q->where('color', $r->string('color')));
        }
        if ($r->boolean('in_stock')) {
            $query->whereHas('variants', fn ($q) => $q->whereColumn('stock', '>', 'reserved'));
        }
        $products = $query->get();
        $sort = $r->input('sort');
        if ($sort === 'price_asc') {
            $products = $products->sortBy(fn ($p) => $p->variants->min('retail_price'));
        }
        if ($sort === 'price_desc') {
            $products = $products->sortByDesc(fn ($p) => $p->variants->min('retail_price'));
        }
        $categories = Product::where('active', true)->distinct()->pluck('category');
        $wishIds = $r->user() ? DB::table('wishlists')->where('user_id', $r->user()->id)->pluck('product_id')->all() : [];

        return view('store', compact('products', 'categories', 'wishIds'));
    }

    public function product(Product $product)
    {
        abort_unless($product->active, 404);
        $product->load('variants');

        return view('product', compact('product'));
    }

    public function add(Request $r)
    {
        $data = $r->validate(['variant_id' => 'required|integer|exists:variants,id', 'quantity' => 'required|integer|min:1|max:10000']);
        $v = Variant::with('product')->findOrFail($data['variant_id']);
        abort_unless($v->product->active, 404);
        $cart = $r->session()->get('cart', []);
        $quantity = ($cart[$v->id] ?? 0) + $data['quantity'];
        if ($quantity > $v->available()) {
            return back()->withErrors(['stock' => 'Only '.$v->available().' pieces are available for this variant.']);
        }
        $cart[$v->id] = $quantity;
        $r->session()->put('cart', $cart);

        return redirect()->route('cart')->with('notice', 'Added to your cart. Your quantity price is applied automatically.');
    }

    public function cart(Request $r, Pricing $pricing)
    {
        $summary = $pricing->cart($r->session()->get('cart', []));

        return view('cart', compact('summary'));
    }

    public function update(Request $r, Variant $variant)
    {
        $data = $r->validate(['quantity' => 'required|integer|min:0|max:10000']);
        $cart = $r->session()->get('cart', []);
        if (! isset($cart[$variant->id])) {
            abort(404);
        }
        if ($data['quantity'] > $variant->available()) {
            return back()->withErrors(['stock' => 'Only '.$variant->available().' pieces are available.']);
        }
        if ($data['quantity'] === 0) {
            unset($cart[$variant->id]);
        } else {
            $cart[$variant->id] = $data['quantity'];
        }
        $r->session()->put('cart', $cart);

        return back();
    }

    public function wishlist(Request $r, Product $product)
    {
        $key = ['user_id' => $r->user()->id, 'product_id' => $product->id];
        if (DB::table('wishlists')->where($key)->exists()) {
            DB::table('wishlists')->where($key)->delete();
        } else {
            DB::table('wishlists')->insert($key + ['created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('notice', 'Wishlist updated.');
    }

    public function checkout(Request $r, Pricing $pricing, PayMongo $pay, Lalamove $delivery)
    {
        $summary = $pricing->cart($r->session()->get('cart', []));
        if (! $summary['quantity']) {
            return redirect()->route('cart');
        }

        return view('checkout', ['summary' => $summary, 'payReady' => $pay->ready(), 'deliveryReady' => $delivery->ready()]);
    }

    public function quote(Request $r, Lalamove $delivery)
    {
        $data = $r->validate(['address' => 'required|string|max:500', 'lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180']);
        try {
            $quote = $delivery->quote($data['address'], (string) $data['lat'], (string) $data['lng']);
            if (data_get($quote, 'priceBreakdown.currency') !== 'PHP' || ! is_numeric(data_get($quote, 'priceBreakdown.total')) || empty($quote['quotationId'])) {
                throw new \RuntimeException('Invalid quote');
            }
            $saved = ['quote' => $quote, 'address' => $data['address'], 'lat' => (string) $data['lat'], 'lng' => (string) $data['lng']];
            $r->session()->put('delivery_quote', $saved);

            return response()->json(['amount' => (int) round((float) $quote['priceBreakdown']['total'] * 100), 'expires_at' => $quote['expiresAt']]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Delivery quotation is unavailable. Check the address and try again, or choose pickup.'], 503);
        }
    }

    public function place(Request $r, PayMongo $pay, OrderFlow $flow)
    {
        $data = $r->validate(['recipient' => 'required|string|max:100', 'phone' => ['required', 'regex:/^\+?[0-9]{10,15}$/'],
            'delivery_method' => 'required|in:pickup,lalamove', 'address' => 'required|string|max:500',
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180']);
        if (! $pay->ready()) {
            return back()->withErrors(['payment' => 'Configure PayMongo test keys and webhook first.'])->withInput();
        }
        $data['shipping'] = 0;
        if ($data['delivery_method'] === 'lalamove') {
            $saved = $r->session()->get('delivery_quote');
            if (! $saved || $saved['address'] !== $data['address'] || $saved['lat'] !== (string) ($data['lat'] ?? '') || $saved['lng'] !== (string) ($data['lng'] ?? '') || now()->gte(Carbon::parse($saved['quote']['expiresAt']))) {
                throw ValidationException::withMessages(['delivery' => 'Get a fresh quotation for this exact delivery address first.']);
            }
            $data['shipping'] = (int) round((float) $saved['quote']['priceBreakdown']['total'] * 100);
            $data['delivery_quote'] = $saved['quote'];
        }
        // One checkout per session; a repeated submission returns the existing order.
        $pendingId = $r->session()->get('pending_order');
        if ($pendingId && ($pending = Order::where('user_id', $r->user()->id)->find($pendingId)) && $pending->status === 'awaiting_payment' && ! $pending->inventory_released) {
            return redirect()->route('orders.show', $pending);
        }
        $order = $flow->reserve($r->session()->get('cart', []), $data, $r->user()->id);
        $r->session()->put('pending_order', $order->id);
        try {
            $session = $pay->create($order->load('items', 'user'));
            $order->update($session);
            $r->session()->forget(['cart', 'delivery_quote']);

            return redirect()->away($session['checkout_url']);
        } catch (\Throwable $e) {
            // Provider outcome may be uncertain after a timeout. Retain reservation and do not create another session blindly.
            return redirect()->route('orders.show', $order)->withErrors(['payment' => 'Checkout could not be confirmed. Your order is saved for staff reconciliation; no payment has been confirmed.']);
        }
    }

    public function orders(Request $r)
    {
        $orders = Order::with('items')->where('user_id', $r->user()->id)->latest()->paginate(12);

        return view('orders', compact('orders'));
    }

    public function order(Request $r, Order $order)
    {
        abort_unless($r->user()->id === $order->user_id || in_array($r->user()->role, ['admin', 'staff']), 403);
        $order->load('items', 'user');

        return view('order', compact('order'));
    }
}
