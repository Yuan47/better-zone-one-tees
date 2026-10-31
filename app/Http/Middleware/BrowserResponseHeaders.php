<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BrowserResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request));
    }

    public static function apply(Response $response): Response
    {
        foreach (['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin'] as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
