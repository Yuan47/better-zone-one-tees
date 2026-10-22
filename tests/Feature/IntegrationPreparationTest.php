<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class IntegrationPreparationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => 'http://localhost', 'trustedproxy.proxies' => [], 'services.google.enabled' => false,
            'services.google.client_id' => null, 'services.google.client_secret' => null,
            'services.brevo.api_key' => null, 'services.brevo.from_email' => null,
            'shop.paymongo.secret' => null, 'shop.lalamove.key' => null, 'shop.groq.key' => null]);
        Route::get('/_integration/proxy', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(), 'google_callback' => route('google.callback'),
            'invoice' => URL::temporarySignedRoute('guest.orders.show', now()->addMinutes(10), ['order' => 1]),
        ]));
    }

    public function test_empty_setup_reports_missing_settings_without_network_or_activation(): void
    {
        $result = $this->check();
        $this->assertFalse($result['configuration_ready']);
        $this->assertFalse($result['activation_performed']);
        $this->assertSame(0, $result['network_requests']);
        $this->assertCount(7, $result['checks']);
        $this->assertFalse(config('services.google.enabled'));
        Http::assertNothingSent();
    }

    public function test_brevo_configuration_check_does_not_send_or_reveal_credentials(): void
    {
        config(['services.brevo.api_key' => 'secret-fixture-must-not-appear',
            'services.brevo.from_email' => 'verified-sender@example.test', 'services.brevo.from_name' => 'Test store']);
        $result = $this->check(['--provider' => 'brevo'], 0);
        $this->assertTrue($result['configuration_ready']);
        $this->assertStringNotContainsString('secret-fixture-must-not-appear', Artisan::output());
        $this->assertStringNotContainsString('verified-sender@example.test', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_google_check_preserves_disabled_flag_and_requires_exact_callback(): void
    {
        config(['services.google.client_id' => 'fixture-client', 'services.google.client_secret' => 'fixture-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback']);
        $this->assertFalse($this->check(['--provider' => 'google'])['configuration_ready']);
        $this->assertFalse(config('services.google.enabled'));
        config(['services.google.enabled' => true, 'services.google.redirect' => 'http://wrong.example/auth/google/callback']);
        $this->assertFalse($this->check(['--provider' => 'google'])['configuration_ready']);
        config(['services.google.redirect' => 'http://localhost/auth/google/callback']);
        $this->assertTrue($this->check(['--provider' => 'google'], 0)['configuration_ready']);
        $this->assertStringNotContainsString('fixture-secret', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_public_profile_rejects_local_debug_demo_and_insecure_sessions(): void
    {
        config(['shop.groq.key' => 'fixture-private-key', 'shop.groq.model' => 'fixture-model',
            'app.debug' => true, 'shop.demo' => true, 'session.secure' => false,
            'session.driver' => 'array', 'cache.default' => 'array']);
        $result = $this->check(['--provider' => 'groq', '--public' => true]);
        $this->assertFalse($result['configuration_ready']);
        $this->assertSame('needs setup', $result['checks'][0]['status']);
        $this->assertSame('needs setup', $result['checks'][1]['status']);
        $this->assertSame('needs setup', $result['checks'][2]['status']);
        $this->assertSame('needs setup', $result['checks'][3]['status']);
        $this->assertStringNotContainsString('fixture-private-key', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_public_configuration_can_pass_without_claiming_provider_verification(): void
    {
        config(['app.url' => 'https://store.example.test', 'app.debug' => false, 'shop.demo' => false,
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'session.driver' => 'database', 'cache.default' => 'database',
            'trustedproxy.proxies' => ['127.0.0.1', '::1', '10.10.0.0/24'],
            'shop.groq.key' => 'fixture-key', 'shop.groq.model' => 'fixture-model']);
        $result = $this->check(['--provider' => 'groq', '--public' => true], 0);
        $this->assertTrue($result['configuration_ready']);
        $this->assertStringContainsString('Configuration only', $result['scope']);
        Http::assertNothingSent();
    }

    public function test_wildcard_proxy_trust_is_flagged_before_public_acceptance(): void
    {
        foreach (['*', 'REMOTE_ADDR', '0.0.0.0/0', '::/0', '127.0.0.1/99'] as $proxy) {
            config(['trustedproxy.proxies' => [$proxy], 'shop.groq.key' => 'fixture-key']);
            $result = $this->check(['--provider' => 'groq']);
            $this->assertSame('needs setup', $result['checks'][1]['status']);
        }
    }

    public function test_public_payment_acceptance_rejects_live_mode_even_with_valid_live_shape(): void
    {
        config(['app.url' => 'https://store.example.test', 'app.debug' => false, 'shop.demo' => false,
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'session.driver' => 'database', 'cache.default' => 'database',
            'shop.paymongo.mode' => 'live', 'shop.paymongo.secret' => 'sk_live_fixture',
            'shop.paymongo.webhook_secret' => 'fixture-signing-secret']);
        $result = $this->check(['--provider' => 'paymongo', '--public' => true]);
        $this->assertSame('needs setup', end($result['checks'])['status']);
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_fixture']);
        $this->assertTrue($this->check(['--provider' => 'paymongo', '--public' => true], 0)['configuration_ready']);
        Http::assertNothingSent();
    }

    public function test_lalamove_checks_pickup_coordinates_without_booking(): void
    {
        config(['shop.lalamove.mode' => 'sandbox', 'shop.lalamove.key' => 'fixture_test_key',
            'shop.lalamove.secret' => 'fixture_test_secret', 'shop.lalamove.address' => 'Test pickup',
            'shop.lalamove.phone' => '+639000000000', 'shop.lalamove.lat' => '999', 'shop.lalamove.lng' => '121']);
        $this->assertFalse($this->check(['--provider' => 'lalamove'])['configuration_ready']);
        config(['shop.lalamove.lat' => '14.5']);
        $this->assertTrue($this->check(['--provider' => 'lalamove'], 0)['configuration_ready']);
        config(['shop.lalamove.phone' => 'invalid']);
        $this->assertFalse($this->check(['--provider' => 'lalamove'])['configuration_ready']);
        Http::assertNothingSent();
    }

    public function test_untrusted_forwarded_headers_cannot_change_return_urls(): void
    {
        $response = $this->proxyRequest('203.0.113.10');
        $response->assertOk()->assertJsonPath('secure', false)->assertJsonPath('google_callback', 'http://localhost/auth/google/callback');
        $this->assertStringStartsWith('http://localhost/guest/invoices/1?', $response->json('invoice'));
    }

    public function test_explicit_trusted_loopback_preserves_https_host_and_signed_urls(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1']]);
        $response = $this->proxyRequest('127.0.0.1');
        $response->assertOk()->assertJsonPath('secure', true)
            ->assertJsonPath('google_callback', 'https://store.example.test/auth/google/callback');
        $signed = $response->json('invoice');
        $this->assertStringStartsWith('https://store.example.test/guest/invoices/1?', $signed);
        $this->assertTrue(URL::hasValidSignature(Request::create($signed)));
        $this->assertStringNotContainsString('/spoofed-prefix', $signed);
    }

    public function test_unknown_provider_is_rejected_without_executing_any_provider(): void
    {
        $this->artisan('shop:check-integrations', ['--provider' => 'unknown'])->assertExitCode(2);
        Http::assertNothingSent();
    }

    private function check(array $options = [], int $expected = 1): array
    {
        $this->assertSame($expected, Artisan::call('shop:check-integrations', $options + ['--json' => true]));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function proxyRequest(string $peer)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $peer])->withHeaders([
            'X-Forwarded-Host' => 'store.example.test', 'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443', 'X-Forwarded-Prefix' => '/spoofed-prefix',
        ])->get('http://localhost/_integration/proxy');
    }
}
