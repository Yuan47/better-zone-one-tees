<?php

namespace App\Http\Middleware;

use App\Services\ActivityRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RecordActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $before = ActivityRecorder::actor($request->user());
        $response = $next($request);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 400) {
            $actor = ActivityRecorder::actor($request->user()) ?? $before;
            foreach ($request->attributes->get(ActivityRecorder::REQUEST_KEY, []) as $event) {
                try {
                    app(ActivityRecorder::class)->recordEvent($actor, $event);
                } catch (\Throwable $exception) {
                    // An audit storage outage must not undo a completed customer action.
                    Log::warning('Store activity could not be recorded.', ['action' => $event['action'] ?? 'unknown']);
                }
            }
        }

        return $response;
    }
}
