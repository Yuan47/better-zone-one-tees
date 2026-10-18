<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CustomerSessionController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()?->role === 'customer', 403);

        return response()->json(['expires_at' => $request->attributes->get('customer_session_expires_at'),
            'server_time' => now()->timestamp])->header('Cache-Control', 'no-store, private');
    }
}
