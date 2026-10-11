<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use App\Services\Lalamove;
use App\Services\ActivityRecorder;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use App\Services\Pricing;
use App\Services\StructuredAddress;
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
        if ($r->boolean('wishlist')) {
            abort_unless($r->user(), 403);
            $query->whereIn('id', DB::table('wishlists')->where('user_id', $r->user()->id)->pluck('product_id'));
        }
        if ($r->filled('size') || $r->filled('color') || $r->boolean('in_stock')) {
            $query->whereHas('variants', function ($q) use ($r) {
                if ($r->filled('size')) {
                    $q->where('size', $r->string('size'));
                }
                if ($r->filled('color')) {
                    $q->where('color', $r->string('color'));
                }
                if ($r->boolean('in_stock')) {
                    $q->whereColumn('stock', '>', 'reserved');
                }
            });
        }
        $products = $query->get();
        $sort = $r->input('sort');
        if ($sort === 'price_asc') {
            $products = $products->sortBy(fn ($p) => $p->variants->min('retail_price'));
        }
        if ($sort === 'price_desc') {
            $products = $products->sortByDesc(fn ($p) => $p->variants->min('retail_price'));
        }
        $filterVariants = Variant::whereHas('product', fn ($q) => $q->where('active', true))->get();
        $sizes = $filterVariants->pluck('size')->unique()->sortBy(fn ($v) => array_search($v, ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL']) === false ? (int) $v : 100 + array_search($v, ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL']))->values();
        $colors = $filterVariants->pluck('color')->unique()->sort()->values();
        $wishIds = $r->user() ? DB::table('wishlists')->where('user_id', $r->user()->id)->pluck('product_id')->all() : [];

        return view('store', compact('products', 'sizes', 'colors', 'wishIds'));
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
        ActivityRecorder::mark($r, 'cart.added', $v, ['quantity' => $quantity, 'previous_quantity' => $quantity - $data['quantity'], 'cart_quantity' => array_sum($cart)]);

        return redirect()->route('cart')->with('notice', 'Added to your cart. Your quantity price is applied automatically.');
    }

    public function cart(Request $r, Pricing $pricing)
    {
        $summary = $pricing->cart($this->activeCart($r));

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
        $previousQuantity = $cart[$variant->id];
        if ($data['quantity'] === 0) {
            unset($cart[$variant->id]);
        } else {
            $cart[$variant->id] = $data['quantity'];
        }
        $r->session()->put('cart', $cart);
        if ($data['quantity'] !== $previousQuantity) {
            ActivityRecorder::mark($r, $data['quantity'] === 0 ? 'cart.removed' : 'cart.updated', $variant,
                ['quantity' => $data['quantity'], 'previous_quantity' => $previousQuantity, 'cart_quantity' => array_sum($cart)]);
        }

        return back();
    }

    public function wishlist(Request $r, Product $product)
    {
        $key = ['user_id' => $r->user()->id, 'product_id' => $product->id];
        if (DB::table('wishlists')->where($key)->exists()) {
            DB::table('wishlists')->where($key)->delete();
            ActivityRecorder::mark($r, 'wishlist.removed', $product);
        } else {
            DB::table('wishlists')->insert($key + ['created_at' => now(), 'updated_at' => now()]);
            ActivityRecorder::mark($r, 'wishlist.added', $product);
        }

        return back()->with('notice', 'Wishlist updated.');
    }

    public function checkout(Request $r, Pricing $pricing, PayMongo $pay, Lalamove $delivery)
    {
        $summary = $pricing->cart($this->activeCart($r));
        if (! $summary['quantity']) {
            return redirect()->route('cart');
        }

        return view('checkout', ['summary' => $summary, 'payReady' => $pay->ready(), 'deliveryReady' => $delivery->ready()]);
    }

    public function quote(Request $r, Lalamove $delivery, StructuredAddress $addresses)
    {
        $data = $addresses->validatedInput($r, true);
        $r->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180']);
        $data['address'] = $addresses->format($data);
        if (strcasecmp($data['country'], 'Philippines') !== 0) {
            throw ValidationException::withMessages(['country' => 'Lalamove delivery is available in the Philippines only.']);
        }
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

    public function place(Request $r, PayMongo $pay, OrderFlow $flow, StructuredAddress $addresses)
    {
        $data = $r->validate(['recipient' => 'required|string|max:100', 'phone' => ['required', 'regex:/^\+?[0-9]{10,15}$/'],
            'delivery_method' => 'required|in:pickup,lalamove',
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180']);
        $address = $addresses->validatedInput($r, $data['delivery_method'] === 'lalamove');
        $data = array_merge($data, $address);
        $data['address'] = filled($data['street_address']) ? $addresses->format($data) : 'Store pickup';
        if ($data['delivery_method'] === 'lalamove' && strcasecmp($data['country'], 'Philippines') !== 0) {
            throw ValidationException::withMessages(['country' => 'Lalamove delivery is available in the Philippines only.']);
        }
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
        ActivityRecorder::mark($r, 'order.created', $order, ['quantity' => $order->quantity, 'delivery_method' => $order->delivery_method, 'status' => $order->fresh()->status]);
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

    private function activeCart(Request $r): array
    {
        $cart = $r->session()->get('cart', []);
        $active = Variant::whereIn('id', array_keys($cart))->whereHas('product', fn ($q) => $q->where('active', true))->pluck('id')->all();
        $clean = array_intersect_key($cart, array_flip($active));
        if (count($clean) !== count($cart)) {
            $r->session()->put('cart', $clean);
            $r->session()->flash('notice', 'Your cart was updated because some products are no longer in the catalog.');
        }

        return $clean;
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
