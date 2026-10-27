<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AccountNavigation
{
    public static function workspace(?User $user): bool
    {
        return $user && in_array($user->role, ['staff', 'admin'], true);
    }

    public static function homeRoute(User $user): string
    {
        return self::workspace($user) ? 'admin.dashboard' : 'store';
    }

    public static function loginRoute(?User $user): string
    {
        return self::workspace($user) ? 'admin.login' : 'login';
    }

    /** Restore only a same-origin GET destination that belongs to the saved account role. */
    public static function destination(Request $request, mixed $target): ?string
    {
        if (! is_string($target) || $target === '' || strlen($target) > 4096
            || preg_match('/[\x00-\x20\x7f\\\\]/', $target) || preg_match('/[\x00-\x1f\x7f\\\\]/', rawurldecode($target))) {
            return null;
        }
        $parts = parse_url($target);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = $parts['scheme'] ?? '';
            if ($scheme !== $request->getScheme() || strtolower($parts['host'] ?? '') !== strtolower($request->getHost())
                || (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) !== $request->getPort()) {
                return null;
            }
        }
        $path = $parts['path'] ?? '/';
        $decoded = rawurldecode($path);
        if (! str_starts_with($path, '/') || str_starts_with($decoded, '//')
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decoded)) {
            return null;
        }
        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpExceptionInterface) {
            return null;
        }
        if (self::workspace($request->user())) {
            $allowed = ($route->named('admin.*') && ! $route->named('admin.login', 'admin.login.submit')) || $route->named('account', 'orders.show');
            if ($request->user()->role !== 'admin' && in_array('staff:admin', $route->gatherMiddleware(), true)) {
                $allowed = false;
            }
        } else {
            $allowed = $route->named('store', 'product', 'cart', 'dashboard', 'account', 'orders', 'orders.show', 'checkout');
        }

        return $allowed ? $target : null;
    }
}
