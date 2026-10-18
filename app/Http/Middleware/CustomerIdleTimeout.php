<?php

namespace App\Http\Middleware;

use App\Services\ActivityRecorder;
use App\Services\CustomerSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CustomerIdleTimeout
{
    public const NOTICE = 'You were signed out after 15 minutes without activity. Sign in again to continue.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'customer' || $request->routeIs('webhooks.*', 'logout')) {
            return $next($request);
        }
        // JSON fetches and background requests cannot keep an unattended login alive.
        $navigation = $request->isMethod('GET') && ! $request->expectsJson() && ! $request->ajax()
            && ! $request->routeIs('customer.session.*', 'guest.orders.*')
            && in_array($request->header('Sec-Fetch-Dest', 'document'), ['document', 'iframe'], true);
        $formAction = ! $request->isMethod('GET') && ! $request->expectsJson() && ! $request->ajax()
            && ! $request->routeIs('customer.session.*', 'guest.orders.*');
        $idleSeconds = $request->routeIs('customer.session.activity')
            ? (int) ($request->validate(['idle_seconds' => 'sometimes|integer|min:0|max:60'])['idle_seconds'] ?? 0) : 0;
        try {
            $expiry = app(CustomerSessions::class)->check($request,
                $navigation || $formAction || $request->routeIs('customer.session.activity'), $idleSeconds);
        } catch (\Throwable $exception) {
            report($exception);

            return $request->expectsJson()
                ? response()->json(['message' => 'Your session could not be checked. Reload the page to try again.'], 503)->header('Cache-Control', 'no-store, private')
                : response('Your session could not be checked. Reload the page to try again.', 503)->header('Cache-Control', 'no-store, private');
        }
        if ($expiry === null) {
            $user = $request->user();
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            ActivityRecorder::mark($request, 'auth.logout', $user);
            // Signed guest invoice links and password recovery remain usable independently of browser login.
            if ($request->routeIs('guest.orders.*', 'password.*')) {
                return $next($request);
            }
            $returnTo = $request->getRequestUri();
            if ($request->isMethod('GET') && $request->routeIs('orders', 'orders.show', 'dashboard', 'account', 'checkout', 'store', 'product', 'cart')
                && str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//') && ! preg_match('/[\\\\\r\n]/', $returnTo)) {
                $request->session()->put('url.intended', $returnTo);
            }

            return $request->expectsJson()
                ? response()->json(['message' => self::NOTICE, 'code' => 'customer_session_expired', 'login_url' => route('login')], 401)->header('Cache-Control', 'no-store, private')
                : redirect()->route('login')->with('notice', self::NOTICE);
        }
        $request->attributes->set('customer_session_expires_at', $expiry);

        return $next($request);
    }
}
