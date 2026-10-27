<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireVerifiedEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('auth.email_verification.required') !== true || ! $request->user()
            || $request->user()->email_verified_at !== null
            || $request->routeIs('verification.*', 'password.*', 'login', 'login.submit', 'admin.login', 'admin.login.submit', 'logout', 'webhooks.paymongo', 'guest.orders.*', 'customer.session.*')
            || ($request->isMethod('GET') && $request->routeIs('store', 'product', 'cart', 'account'))) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Verify your email before continuing.', 'verification_url' => route('verification.notice')], 403);
        }
        if ($request->isMethod('GET')) {
            $request->session()->put('email_verification.return_to', $request->getRequestUri());
        }

        return redirect()->route('verification.notice');
    }
}
