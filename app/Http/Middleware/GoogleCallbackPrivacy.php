<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GoogleCallbackPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        } finally {
            // StartSession saves the previous GET URL afterwards, including on a rejected callback.
            $request->query->replace([]);
            $request->server->set('QUERY_STRING', '');
        }
    }
}
