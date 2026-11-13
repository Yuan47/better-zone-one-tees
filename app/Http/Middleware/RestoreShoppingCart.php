<?php

namespace App\Http\Middleware;

use App\Services\SavedCart;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestoreShoppingCart
{
    public function handle(Request $request, Closure $next): Response
    {
        // Payment callbacks and private invoices must not depend on browser-cart storage.
        if ($request->routeIs('webhooks.*', 'guest.orders.*', 'customer.session.*')) {
            return $next($request);
        }
        $carts = app(SavedCart::class);
        $carts->open($request);
        $response = $next($request);
        $carts->close($request);

        return $response;
    }
}
