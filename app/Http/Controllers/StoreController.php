<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use App\Services\InstallmentCredit;
use App\Services\InvoicePayments;
use App\Services\InvoiceTerms;
use App\Services\Lalamove;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use App\Services\Pricing;
use App\Services\StructuredAddress;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $sizes = $filterVariants->pluck('size')->unique()->sortBy(fn ($size) => $this->sizeSortKey($size))->values();
        $colors = $filterVariants->pluck('color')->unique()->sort()->values();
        $wishIds = $r->user() ? DB::table('wishlists')->where('user_id', $r->user()->id)->pluck('product_id')->all() : [];
        $paymentAlerts = app(InstallmentCredit::class)->alertsFor($r->user());

        return view('store', compact('products', 'sizes', 'colors', 'wishIds', 'paymentAlerts'));
    }

    public function product(Product $product)
    {
        abort_unless($product->active, 404);
        $product->load('variants');
        $product->setRelation('variants', $product->variants->sortBy(fn ($variant) => $this->sizeSortKey($variant->size))->values());

        return view('product', compact('product'));
    }

    private function sizeSortKey(string $size): array
    {
        $label = strtoupper(trim($size));
        if (preg_match('/^#?(\d+)$/D', $label, $matches)) {
            return [0, (int) $matches[1], $label];
        }

        $adult = ['TN', 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];
        $normalized = preg_replace('/^([2-5])X$/D', '${1}XL', $label);
        $rank = array_search($normalized, $adult, true);
        if ($rank !== false) {
            return [1, $rank, $label];
        }

        return $label === 'FREE SIZE' ? [2, 0, $label] : [3, 0, $label];
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
        $r->session()->forget(['checkout_key', 'checkout_cart_consumed']);
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
        $data['quantity'] = (int) $data['quantity'];
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
            $r->session()->forget(['checkout_key', 'checkout_cart_consumed']);
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
        $this->requireCustomerCheckout($r);
        $summary = $pricing->cart($this->activeCart($r));
        if (! $summary['quantity']) {
            return redirect()->route('cart');
        }

        return view('checkout', ['summary' => $summary, 'payReady' => $pay->ready(), 'deliveryReady' => $delivery->ready(), 'checkoutKey' => $this->checkoutKey($r)]);
    }

    public function quote(Request $r, Lalamove $delivery, StructuredAddress $addresses)
    {
        $this->requireCustomerCheckout($r);
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
        $this->requireCustomerCheckout($r);
        $checkoutKey = $r->validate(['checkout_key' => 'nullable|uuid'])['checkout_key'] ?? null;
        if ($checkoutKey === null) {
            $checkoutKey = $this->checkoutKey($r);
        }
        $checkoutKey = strtolower($checkoutKey);
        if ($existing = Order::where('creation_key', $checkoutKey)->first()) {
            if ($existing->user_id !== $r->user()->id || $existing->sales_channel !== 'online') {
                throw ValidationException::withMessages(['checkout_key' => 'Refresh checkout before submitting this purchase.']);
            }

            return redirect()->route('orders.show', $existing);
        }
        if (! in_array($checkoutKey, $r->session()->get('checkout_keys', []), true)) {
            throw ValidationException::withMessages(['checkout_key' => 'Refresh checkout before submitting this purchase.']);
        }
        $data = $r->validate(['recipient' => 'required|string|max:100', 'phone' => ['required', 'regex:/^\+?[0-9]{10,15}$/'],
            'delivery_method' => 'required|in:pickup,lalamove',
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180',
            'payment_option' => 'nullable|in:full,partial', 'payment_amount' => 'required_if:payment_option,partial', 'payment_days' => 'nullable|integer|between:7,30']);
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
        $partial = ($data['payment_option'] ?? 'full') === 'partial';
        $amount = $partial ? InvoiceTerms::amount($data['payment_amount']) : null;
        $data['payment_plan'] = $partial ? 'partial' : 'full';
        $data['creation_key'] = $checkoutKey;
        if ($partial) {
            $data['due_at'] = now()->addDays((int) ($data['payment_days'] ?? app(InvoiceTerms::class)->days()));
        }
        $order = DB::transaction(function () use ($flow, $r, $data, $partial, $amount) {
            $invoice = $flow->reserve($r->session()->get('cart', []), $data, $r->user()->id);
            if ($partial) {
                app(InvoiceTerms::class)->validatePartial($invoice);
                if ($amount >= $invoice->total) {
                    throw ValidationException::withMessages(['payment_amount' => 'A partial payment must be less than the original invoice total. Choose full payment to settle it now.']);
                }
            }

            return $invoice;
        }, 3);
        $r->session()->put(['pending_order' => $order->id, 'checkout_key' => $checkoutKey, 'checkout_cart_consumed' => false]);
        ActivityRecorder::mark($r, 'order.created', $order, ['quantity' => $order->quantity, 'delivery_method' => $order->delivery_method, 'status' => $order->fresh()->status]);
        try {
            $attempt = app(InvoicePayments::class)->start($order, $amount ?? $order->total, $r->user(), (string) Str::uuid());
            $r->session()->forget(['cart', 'delivery_quote']);
            $r->session()->put('checkout_cart_consumed', true);

            return redirect()->away($attempt->checkout_url);
        } catch (\Throwable $e) {
            // Provider outcome may be uncertain after a timeout. Retain reservation and do not create another session blindly.
            return redirect()->route('orders.show', $order)->withErrors(['payment' => 'Checkout could not be confirmed. Your order is saved for staff reconciliation; no payment has been confirmed.']);
        }
    }

    private function checkoutKey(Request $request): string
    {
        $current = $request->session()->get('checkout_key');
        $closed = $current && Order::where('user_id', $request->user()->id)->where('creation_key', $current)
            ->where(fn ($query) => $query->where('status', 'cancelled')->orWhere('inventory_released', true))->exists();
        // Revisiting unchanged checkout after an uncertain outcome must keep the original request.
        if ($current && ! $closed && ! ($request->session()->get('checkout_cart_consumed') && $request->session()->get('cart', []))) {
            return $current;
        }
        $key = (string) Str::uuid();
        $keys = $request->session()->get('checkout_keys', []);
        $keys[] = $key;
        $request->session()->put(['checkout_key' => $key, 'checkout_keys' => array_slice($keys, -20), 'checkout_cart_consumed' => false]);

        return $key;
    }

    private function requireCustomerCheckout(Request $request): void
    {
        $user = $request->user();
        if ($user && $user->role === null && $user->exists) {
            $user = $user->fresh();
        }
        abort_unless($user?->role === 'customer', 403, 'Please sign in with a customer account to check out.');
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
        $order->load('items', 'user', 'refunds.paymentAttempt', 'review', 'payments.recorder');

        return view('order', compact('order'));
    }
}
