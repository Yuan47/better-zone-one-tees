<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class StaffAccess
{
    public function handle(Request $r, Closure $next, string $role = 'staff')
    {
        abort_unless($r->user() && in_array($r->user()->role, $role === 'admin' ? ['admin'] : ['admin', 'staff']), 403);

        return $next($r);
    }
}
