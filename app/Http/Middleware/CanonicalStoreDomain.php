<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalStoreDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.canonical_redirect') !== true) {
            return $next($request);
        }

        $url = config('app.url');
        $parts = is_string($url) ? parse_url($url) : false;
        abort_unless(is_array($parts) && ($parts['scheme'] ?? null) === 'https' && filled($parts['host'] ?? null)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']) && in_array($parts['path'] ?? '', ['', '/'], true), 503);
        $host = strtolower($request->getHost());
        if ($host === strtolower($parts['host'])) {
            return $next($request);
        }

        $legacy = in_array($host, array_map('strtolower', config('app.legacy_hosts', [])), true);
        // Signed guest links include their original hostname. Their normal
        // signature/CSRF checks still run, using the unchanged application key.
        // Existing signed provider callbacks must also survive a domain move.
        if ($legacy && ($request->is('guest/invoices/*') || $request->is('webhooks/paymongo'))) {
            return $next($request);
        }

        $safeGet = in_array($request->method(), ['GET', 'HEAD'], true);
        $destination = $safeGet && ! $request->is('auth/google/callback')
            ? $request->getRequestUri()
            : ($request->is('admin', 'admin/*') ? '/admin/login' : '/login');

        // Never forward old form bodies, passwords or Google authorization
        // codes to a different hostname. The user signs in on the new address.
        return redirect()->away(rtrim($url, '/').$destination, $safeGet ? 302 : 303)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
