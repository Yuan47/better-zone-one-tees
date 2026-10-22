<?php

namespace App\Console\Commands;

use App\Services\BrevoTransactions;
use App\Services\GoogleSignIn;
use App\Services\Lalamove;
use App\Services\PayMongo;
use Illuminate\Console\Command;

class CheckIntegrations extends Command
{
    protected $signature = 'shop:check-integrations {--provider=all : all, brevo, google, paymongo, lalamove, or groq}
        {--public : Require the settings needed before a public HTTPS acceptance test}
        {--json : Return a secret-free JSON report}';

    protected $description = 'Check integration settings without network requests, emails, database writes, or activation';

    public function handle(): int
    {
        $provider = $this->option('provider');
        $providers = ['brevo', 'google', 'paymongo', 'lalamove', 'groq'];
        if (! in_array($provider, ['all', ...$providers], true)) {
            $this->error('Choose all, brevo, google, paymongo, lalamove, or groq.');

            return self::INVALID;
        }
        $checks = [];
        $url = parse_url((string) config('app.url'));
        $validUrl = is_array($url) && in_array($url['scheme'] ?? '', ['http', 'https'], true)
            && filled($url['host'] ?? null) && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment']);
        $public = (bool) $this->option('public');
        $loopback = in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
        $checks[] = $this->row('Application URL', $validUrl && (! $public || ($url['scheme'] === 'https' && ! $loopback)),
            'APP_URL must be a valid origin/path; public testing requires a reachable public HTTPS URL.');
        if ($public) {
            $checks[] = $this->row('Public debug/demo settings', config('app.debug') === false && config('shop.demo') === false,
                'Set APP_DEBUG=false and SHOP_DEMO=false. Replace known demo credentials before exposure.');
            $checks[] = $this->row('HTTPS session cookies', config('session.secure') === true && config('session.http_only') === true
                && config('session.same_site') === 'lax', 'Use secure, HTTP-only, SameSite=lax cookies for the Google callback.');
            $checks[] = $this->row('Persistent session/cache', ! in_array(config('session.driver'), ['array', 'cookie'], true)
                && ! in_array(config('cache.default'), ['array', 'null'], true), 'Use persistent session/cache stores with lock support.');
        }
        $proxyList = config('trustedproxy.proxies', []);
        $checks[] = $this->row('Proxy trust', is_array($proxyList) && collect($proxyList)->every(fn ($ip) => $this->validProxy($ip)),
            'Use explicit proxy IPs/CIDRs, not wildcard trust. Empty is correct for direct localhost; verify actual forwarding during tunnel setup.');
        foreach ($provider === 'all' ? $providers : [$provider] as $selected) {
            [$ready, $note] = match ($selected) {
                'brevo' => [app(BrevoTransactions::class)->configured(), 'Set BREVO_API_KEY, BREVO_FROM_EMAIL and BREVO_FROM_NAME. Sender approval and actual inbox delivery still require testing.'],
                'google' => [app(GoogleSignIn::class)->ready(), 'Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and the exact GOOGLE_REDIRECT_URI matching APP_URL; enable GOOGLE_SIGN_IN_ENABLED after setup.'],
                'paymongo' => [app(PayMongo::class)->ready() && (! $public || config('shop.paymongo.mode') === 'test'), 'Use PAYMONGO_MODE=test with a test secret, webhook signing secret and account-enabled payment methods.'],
                'lalamove' => [app(Lalamove::class)->ready() && $this->validCoordinates()
                    && Lalamove::internationalPhone((string) config('shop.lalamove.phone')) !== null
                    && (! $public || config('shop.lalamove.mode') === 'sandbox'), 'Use sandbox credentials and complete pickup address, coordinates, phone and supported service type.'],
                'groq' => [filled(config('shop.groq.key')) && filled(config('shop.groq.model')), 'Set GROQ_API_KEY and an account-available GROQ_MODEL. Basic approved answers remain available without AI.'],
            };
            $checks[] = $this->row(match ($selected) {
                'paymongo' => 'PayMongo', 'groq' => 'GroqCloud', default => ucfirst($selected)
            }, $ready, $note);
        }
        $result = ['configuration_ready' => collect($checks)->every(fn ($row) => $row['status'] === 'ready'),
            'scope' => 'Configuration only; no provider credentials, sender approval, public reachability, schema, or inbox delivery verified.',
            'network_requests' => 0, 'activation_performed' => false, 'checks' => $checks];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'Next action'], array_map(fn ($row) => array_values($row), $checks));
            $this->line($result['scope']);
        }

        return $result['configuration_ready'] ? self::SUCCESS : self::FAILURE;
    }

    private function row(string $label, bool $ready, string $note): array
    {
        return ['check' => $label, 'status' => $ready ? 'ready' : 'needs setup', 'next_action' => $note];
    }

    private function validCoordinates(): bool
    {
        $lat = config('shop.lalamove.lat');
        $lng = config('shop.lalamove.lng');

        return is_numeric($lat) && is_numeric($lng) && is_finite((float) $lat) && is_finite((float) $lng)
            && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lng >= -180 && (float) $lng <= 180;
    }

    private function validProxy(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $parts = explode('/', $value);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return count($parts) === 1 || (ctype_digit($parts[1]) && (int) $parts[1] > 0
            && (int) $parts[1] <= (str_contains($parts[0], ':') ? 128 : 32));
    }
}
