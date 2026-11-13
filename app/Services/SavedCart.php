<?php

namespace App\Services;

use App\Models\ShoppingCart;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SavedCart
{
    public const COOKIE = 'zoneone_cart';

    private const CONTEXT = 'saved_cart_context';

    public function open(Request $request): void
    {
        $user = $this->customer($request);
        $context = $request->session()->get(self::CONTEXT);
        $cart = $this->clean($request->session()->get('cart', []));
        if ($user) {
            $owner = 'customer:'.$user->id;
            if ($context !== $owner) {
                // Switching accounts must never import another customer's session cart.
                $guest = is_string($context) && str_starts_with($context, 'customer:') ? [] : $cart;
                $cart = DB::transaction(function () use ($user, $guest) {
                    User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $saved = ShoppingCart::firstOrCreate(['user_id' => $user->id], ['items' => []]);
                    $merged = $this->clean($saved->items);
                    foreach ($guest as $id => $quantity) {
                        $merged[$id] = max($merged[$id] ?? 0, $quantity);
                    }
                    $saved->update(['items' => $merged]);

                    return $merged;
                }, 3);
                if ($token = $this->guestToken($request)) {
                    ShoppingCart::where('guest_token_hash', hash('sha256', $token))->whereNull('user_id')->delete();
                }
                Cookie::queue(Cookie::forget(self::COOKIE));
            } else {
                // A second browser may have changed or checked out the saved cart.
                // Reload it before applying this request's explicit quantity changes.
                $saved = ShoppingCart::where('user_id', $user->id)->first();
                if ($saved) {
                    $cart = $this->clean($saved->items);
                }
            }
            $request->session()->put(self::CONTEXT, $owner);
        } elseif ($request->user()) {
            if (is_string($context) && str_starts_with($context, 'customer:')) {
                $cart = [];
            }
            $request->session()->put(self::CONTEXT, 'workspace');
        } else {
            if (is_string($context) && str_starts_with($context, 'customer:')) {
                $cart = [];
                Cookie::queue(Cookie::forget(self::COOKIE));
            } elseif (! $request->session()->has('cart') && ($token = $this->guestToken($request))) {
                $cart = $this->clean(ShoppingCart::whereNull('user_id')->where('guest_token_hash', hash('sha256', $token))->first()?->items ?? []);
            }
            $request->session()->put(self::CONTEXT, 'guest');
        }
        $request->session()->put('cart', $cart);
        $request->attributes->set('saved_cart_before', $cart);
    }

    public function close(Request $request): void
    {
        if ($request->attributes->get('saved_cart_consumed')) {
            return;
        }
        $user = $this->customer($request);
        if ($user && $request->session()->get(self::CONTEXT) !== 'customer:'.$user->id) {
            $this->open($request);
        }
        if ($request->routeIs('logout')) {
            Cookie::queue(Cookie::forget(self::COOKIE));

            return;
        }
        $before = $request->attributes->get('saved_cart_before', []);
        $after = $this->clean($request->session()->get('cart', []));
        if ($before === $after) {
            return;
        }
        if ($user) {
            $cart = DB::transaction(function () use ($user, $before, $after, $request) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $saved = ShoppingCart::firstOrCreate(['user_id' => $user->id], ['items' => []]);
                $latest = $this->clean($saved->items);
                foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $id) {
                    if (($before[$id] ?? 0) === ($after[$id] ?? 0)) {
                        continue;
                    }
                    $quantity = $request->routeIs('cart.add')
                        ? max(0, ($latest[$id] ?? 0) + ($after[$id] ?? 0) - ($before[$id] ?? 0))
                        : ($after[$id] ?? 0);
                    if ($quantity > 10000) {
                        $request->session()->forget('notice');
                        $request->attributes->set(ActivityRecorder::REQUEST_KEY, array_values(array_filter(
                            $request->attributes->get(ActivityRecorder::REQUEST_KEY, []),
                            static fn ($event) => ($event['action'] ?? null) !== 'cart.added',
                        )));
                        throw ValidationException::withMessages(['quantity' => 'Your cart changed in another browser. It can hold up to 10,000 pieces of each variant.']);
                    }
                    if ($quantity > 0) {
                        $latest[$id] = $quantity;
                    } else {
                        unset($latest[$id]);
                    }
                }
                $saved->update(['items' => $latest]);

                return $latest;
            }, 3);
            $request->session()->put('cart', $cart);
        } elseif (! $request->user()) {
            $token = $this->guestToken($request) ?? $request->session()->get('guest_cart_token') ?? bin2hex(random_bytes(32));
            ShoppingCart::updateOrCreate(['guest_token_hash' => hash('sha256', $token), 'user_id' => null], ['items' => $after]);
            $request->session()->put('guest_cart_token', $token);
            Cookie::queue(cookie(self::COOKIE, $token, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }
    }

    public function consume(Request $request, array $orderedItems): void
    {
        $user = $this->customer($request);
        abort_unless($user, 403);
        $remaining = DB::transaction(function () use ($user, $request, $orderedItems) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $saved = ShoppingCart::firstOrCreate(['user_id' => $user->id], ['items' => $this->clean($request->session()->get('cart', []))]);
            $items = $this->clean($saved->items);
            foreach ($orderedItems as $id => $quantity) {
                $left = max(0, ($items[$id] ?? 0) - (int) $quantity);
                if ($left > 0) {
                    $items[$id] = $left;
                } else {
                    unset($items[$id]);
                }
            }
            $saved->update(['items' => $items]);

            return $items;
        }, 3);
        $request->session()->put('cart', $remaining);
        $request->attributes->set('saved_cart_consumed', true);
    }

    private function customer(Request $request): ?User
    {
        $user = $request->user();
        if ($user && $user->role === null) {
            $user->refresh();
        }

        return $user?->role === 'customer' ? $user : null;
    }

    private function guestToken(Request $request): ?string
    {
        $token = $request->cookie(self::COOKIE);

        return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : null;
    }

    private function clean(mixed $items): array
    {
        if (! is_array($items) || $items === []) {
            return [];
        }
        $normalized = [];
        foreach ($items as $id => $quantity) {
            if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
                && filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) !== false) {
                $normalized[(int) $id] = (int) $quantity;
            }
        }
        $active = Variant::sellable()->whereIn('id', array_keys($normalized))->pluck('id')->all();

        return array_intersect_key($normalized, array_flip($active));
    }
}
