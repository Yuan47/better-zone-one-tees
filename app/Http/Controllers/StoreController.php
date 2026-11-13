<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderReview;
use App\Models\Product;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use App\Services\InstallmentCredit;
use App\Services\InvoicePayments;
use App\Services\CustomerAddresses;
use App\Services\SavedCart;
use App\Services\Lalamove;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use App\Services\Pricing;
use App\Services\StructuredAddress;
use App\Support\FormInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreController extends Controller
{
    public function index(Request $r)
    {
        $search = FormInput::query('q');
        $size = FormInput::query('size');
        $color = FormInput::query('color');
        $query = Product::published()->with(['variants' => fn ($variants) => $variants->whereNull('archived_at')]);
        if (filled($search)) {
            $query->where('name', 'like', '%'.mb_substr((string) $search, 0, 100).'%');
        }
        if ($r->boolean('wishlist')) {
            abort_unless($r->user(), 403);
            $query->whereIn('id', DB::table('wishlists')->where('user_id', $r->user()->id)->pluck('product_id'));
        }
        if (filled($size) || filled($color) || $r->boolean('in_stock')) {
            $query->whereHas('variants', function ($q) use ($r, $size, $color) {
                $q->whereNull('archived_at');
                if (filled($size)) {
                    $q->where('size', $size);
                }
                if (filled($color)) {
                    $q->where('color', $color);
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
        $filterVariants = Variant::sellable()->get();
        $sizes = $filterVariants->pluck('size')->unique()->sortBy(fn ($size) => $this->sizeSortKey($size))->values();
        $colors = $filterVariants->pluck('color')->unique()->sort()->values();
        $wishIds = $r->user() ? DB::table('wishlists')->where('user_id', $r->user()->id)->pluck('product_id')->all() : [];
        $paymentAlerts = app(InstallmentCredit::class)->alertsFor($r->user());

        return view('store', compact('products', 'sizes', 'colors', 'wishIds', 'paymentAlerts'));
    }

    public function product(Request $request, Product $product)
    {
        abort_unless($product->active && ! $product->archived_at, 404);
        $product->load(['variants' => fn ($variants) => $variants->whereNull('archived_at')]);
        $product->setRelation('variants', $product->variants->sortBy(fn ($variant) => $this->sizeSortKey($variant->size))->values());

        // Reviews are order ratings, displayed once for each product in that verified purchase.
        $reviewQuery = OrderReview::query()->verifiedPurchase()->forProduct($product->id);
        $ratingCounts = (clone $reviewQuery)->select('rating')->selectRaw('COUNT(*) as total')
            ->groupBy('rating')->pluck('total', 'rating')->map(fn ($count) => (int) $count);
        $reviewCount = $ratingCounts->sum();
        $averageRating = $reviewCount > 0 ? $ratingCounts->sum(fn ($count, $rating) => $count * (int) $rating) / $reviewCount : null;
        $ratingFilter = in_array($request->query('rating'), ['1', '2', '3', '4', '5'], true) ? (int) $request->query('rating') : null;
        $reviews = (clone $reviewQuery)->when($ratingFilter, fn ($query) => $query->where('rating', $ratingFilter))
            ->with(['user:id,name', 'order:id', 'order.items' => fn ($items) => $items
                ->whereHas('variant', fn ($variant) => $variant->where('product_id', $product->id))
                ->select('id', 'order_id', 'size', 'color')])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(5, ['order_reviews.id', 'order_id', 'user_id', 'rating', 'comment', 'created_at'], 'reviews_page')
            ->appends($ratingFilter ? ['rating' => $ratingFilter] : [])->fragment('product-reviews');

        return view('product', compact('product', 'ratingCounts', 'reviewCount', 'averageRating', 'ratingFilter', 'reviews'));
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
        $v = Variant::sellable()->with('product')->findOrFail($data['variant_id']);
        $cart = $r->session()->get('cart', []);
        $quantity = ($cart[$v->id] ?? 0) + $data['quantity'];
        if ($quantity > 10000) {
            return back()->withErrors(['quantity' => 'Your cart can hold up to 10,000 pieces of each variant.']);
        }
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
        app(CustomerAddresses::class)->applySelected($r);
        $data = $addresses->validatedInput($r, true);
        $r->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180',
            'pin_confirmed' => 'required|accepted']);
        $data['address'] = $addresses->format($data);
        if (strcasecmp($data['country'], 'Philippines') !== 0) {
            throw ValidationException::withMessages(['country' => 'Lalamove delivery is available in the Philippines only.']);
        }
        $r->session()->forget('delivery_quote');
        try {
            $quote = $delivery->quote($data['address'], (string) $data['lat'], (string) $data['lng']);
            $cost = Lalamove::quotationCost($quote);
            $saved = ['quote' => $quote, 'address' => $data['address'], 'lat' => (string) $data['lat'], 'lng' => (string) $data['lng']];
            $r->session()->put('delivery_quote', $saved);

            return response()->json(['amount' => $cost, 'expires_at' => $quote['expiresAt']]);
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
        if ($r->input('delivery_method') === 'lalamove') {
            app(CustomerAddresses::class)->applySelected($r);
        }
        $data = $r->validate(['recipient' => 'required|string|max:100', 'phone' => ['required', new \App\Rules\PhilippineMobileNumber],
            'delivery_method' => 'required|in:pickup,lalamove',
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180',
            'payment_option' => 'nullable|in:full', 'payment_amount' => 'prohibited', 'payment_days' => 'prohibited',
            'terms' => 'required|accepted']);
        if ($data['delivery_method'] === 'lalamove') {
            $r->validate(['pin_confirmed' => 'required|accepted', 'lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180']);
            $address = $addresses->validatedInput($r, true);
        } else {
            $address = array_fill_keys(StructuredAddress::FIELDS, null);
            $data['lat'] = $data['lng'] = null;
        }
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
            try {
                if (! is_array($saved) || ! is_array($saved['quote'] ?? null) || ($saved['address'] ?? null) !== $data['address']
                    || ($saved['lat'] ?? null) !== (string) ($data['lat'] ?? '') || ($saved['lng'] ?? null) !== (string) ($data['lng'] ?? '')) {
                    throw new \UnexpectedValueException('The saved delivery address or quotation is missing.');
                }
                $data['shipping'] = Lalamove::quotationCost($saved['quote']);
            } catch (\Throwable $exception) {
                $r->session()->forget('delivery_quote');
                throw ValidationException::withMessages(['delivery' => 'Get a fresh quotation for this exact delivery address first.']);
            }
            $data['delivery_quote'] = $saved['quote'];
        }
        $data['payment_plan'] = 'full';
        $data += \App\Services\StoreTerms::acceptance();
        $data['creation_key'] = $checkoutKey;
        $orderedCart = $r->session()->get('cart', []);
        // Reserve and consume the saved cart together, even if the provider response later times out.
        $order = DB::transaction(function () use ($r, $flow, $orderedCart, $data) {
            $order = $flow->reserve($orderedCart, $data, $r->user()->id);
            app(SavedCart::class)->consume($r, $orderedCart);

            return $order;
        }, 3);
        $r->session()->put(['pending_order' => $order->id, 'checkout_key' => $checkoutKey, 'checkout_cart_consumed' => true]);
        ActivityRecorder::mark($r, 'order.created', $order, ['quantity' => $order->quantity, 'delivery_method' => $order->delivery_method, 'status' => $order->fresh()->status]);
        try {
            $attempt = app(InvoicePayments::class)->start($order, $order->total, $r->user(), (string) Str::uuid());
            $r->session()->forget('delivery_quote');

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
        $active = Variant::sellable()->whereIn('id', array_keys($cart))->pluck('id')->all();
        $clean = array_intersect_key($cart, array_flip($active));
        if (count($clean) !== count($cart)) {
            $r->session()->put('cart', $clean);
            $r->session()->flash('notice', 'Your cart was updated because some products are no longer in the catalog.');
        }

        return $clean;
    }

    public function orders(Request $r)
    {
        return view('orders', app(\App\Services\CustomerPurchases::class)->page($r));
    }

    public function order(Request $r, Order $order)
    {
        abort_unless($r->user()->id === $order->user_id || in_array($r->user()->role, ['admin', 'staff']), 403);
        $order->load('items', 'user', 'refunds.paymentAttempt', 'review', 'payments.recorder');

        return view('order', compact('order'));
    }
}
