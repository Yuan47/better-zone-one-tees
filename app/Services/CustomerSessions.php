<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CustomerSessions
{
    public const IDLE_SECONDS = 900;

    public const SESSION_KEY = 'customer_idle';

    public function start(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'id' => (string) Str::uuid(), 'user_id' => $request->user()->id,
            'expires_at' => now()->timestamp + self::IDLE_SECONDS,
        ]);
    }

    /** Shared cache state prevents an older in-flight session save reviving an expired login. */
    public function check(Request $request, bool $activity = false, int $idleSeconds = 0): ?int
    {
        $state = $request->session()->get(self::SESSION_KEY);
        if (! is_array($state) || ($state['user_id'] ?? null) !== $request->user()->id) {
            // Existing logins receive their first deadline when this feature is deployed.
            $this->start($request);
            $state = $request->session()->get(self::SESSION_KEY);
        }
        if (! is_string($state['id'] ?? null) || ! is_int($state['expires_at'] ?? null)) {
            return null;
        }
        $key = $this->key($state);

        $expiry = Cache::lock($key.':lock', 5)->block(3, function () use ($state, $key, $activity, $idleSeconds) {
            $current = Cache::get($key);
            // Cache eviction falls back to the original server session deadline, never a fresh grace period.
            $expiry = is_array($current) ? ($current['expires_at'] ?? null) : $state['expires_at'];
            if (! is_int($expiry) || ($current['expired'] ?? false) || now()->timestamp >= $expiry) {
                Cache::put($key, ['expires_at' => $expiry, 'expired' => true], $this->retention());

                return null;
            }
            if ($activity) {
                $expiry = max($expiry, now()->timestamp - $idleSeconds + self::IDLE_SECONDS);
            }
            Cache::put($key, ['expires_at' => $expiry, 'expired' => false], $this->retention());

            return $expiry;
        });
        if ($expiry !== null) {
            $request->session()->put(self::SESSION_KEY, array_replace($state, ['expires_at' => $expiry]));
        }

        return $expiry;
    }

    public function end(Request $request): void
    {
        $state = $request->session()->get(self::SESSION_KEY);
        if (! is_array($state) || ! is_string($state['id'] ?? null) || ! isset($state['user_id'])) {
            return;
        }
        $key = $this->key($state);
        Cache::lock($key.':lock', 5)->block(3, fn () => Cache::put($key,
            ['expires_at' => now()->timestamp, 'expired' => true], $this->retention()));
    }

    private function key(array $state): string
    {
        return 'customer-idle:'.hash('sha256', $state['user_id'].'|'.$state['id']);
    }

    private function retention(): int
    {
        return max(1800, ((int) config('session.lifetime', 120) + 15) * 60);
    }
}
