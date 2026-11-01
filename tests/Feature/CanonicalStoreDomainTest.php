<?php

namespace Tests\Feature;

use App\Http\Middleware\CanonicalStoreDomain;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CanonicalStoreDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://new.example.test', 'app.canonical_redirect' => true, 'app.legacy_hosts' => ['old.example.test']]);
        Http::preventStrayRequests();
    }

    private function through(string $url, string $method = 'GET', array $data = []): Response
    {
        return app(CanonicalStoreDomain::class)->handle(Request::create($url, $method, $data), fn () => new Response('next middleware', 209));
    }

    public function test_canonical_host_and_disabled_local_configuration_pass_through(): void
    {
        $this->assertSame(209, $this->through('https://new.example.test/orders/1')->getStatusCode());
        config(['app.canonical_redirect' => false]);
        $this->assertSame(209, $this->through('http://localhost:8081/register', 'POST')->getStatusCode());
    }

    public function test_ordinary_legacy_get_preserves_path_and_query_on_the_fixed_canonical_host(): void
    {
        $response = $this->through('https://old.example.test/admin/orders?sort=total&direction=desc');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://new.example.test/admin/orders?sort=total&direction=desc', $response->headers->get('Location'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_google_callback_never_forwards_codes_or_state(): void
    {
        $response = $this->through('https://old.example.test/auth/google/callback?code=private-code&state=private-state');
        $this->assertSame('https://new.example.test/login', $response->headers->get('Location'));
        $this->assertStringNotContainsString('private-', (string) $response);
    }

    public static function oldPosts(): array
    {
        return [['/login', '/login'], ['/register', '/login'], ['/auth/google', '/login'],
            ['/orders/1/payments?amount=100', '/login'], ['/admin/orders/1', '/admin/login']];
    }

    #[DataProvider('oldPosts')]
    public function test_old_form_bodies_and_query_strings_are_not_forwarded(string $path, string $destination): void
    {
        $response = $this->through('https://old.example.test'.$path, 'POST', ['password' => 'private-password', 'amount' => '100']);
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('https://new.example.test'.$destination, $response->headers->get('Location'));
        $this->assertStringNotContainsString('private-password', (string) $response);
    }

    public function test_only_exact_legacy_hosts_keep_guest_routes_and_webhooks(): void
    {
        foreach (['/guest/invoices/12?signature=test', '/webhooks/paymongo'] as $path) {
            $this->assertSame(209, $this->through('https://old.example.test'.$path, 'POST')->getStatusCode());
            $this->assertSame(303, $this->through('https://old.example.test.evil.invalid'.$path, 'POST')->getStatusCode());
        }
        $this->assertSame('https://new.example.test/login', $this->through('https://evil.invalid/login')->headers->get('Location'));
    }

    public static function invalidCanonicalUrls(): array
    {
        return [['http://new.example.test'], ['https://user@new.example.test'], ['https://new.example.test/other'],
            ['https://new.example.test?next=other'], ['https://new.example.test#fragment']];
    }

    #[DataProvider('invalidCanonicalUrls')]
    public function test_invalid_canonical_configuration_fails_closed(string $url): void
    {
        config(['app.url' => $url]);
        $this->expectException(HttpException::class);
        $this->through('https://old.example.test/');
    }

    public function test_actual_signed_legacy_guest_invoice_still_validates_without_exposing_invalid_links(): void
    {
        $order = Order::create(['number' => 'ZOT-LEGACY-LINK', 'user_id' => null, 'tier' => 'retail', 'quantity' => 1,
            'subtotal' => 10000, 'merchandise_base' => 10000, 'discount' => 0, 'shipping' => 0, 'total' => 10000,
            'amount_paid' => 0, 'payment_status' => 'pending', 'status' => 'awaiting_payment', 'sales_channel' => 'walk_in',
            'recipient' => 'Private guest', 'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup']);
        URL::forceRootUrl('https://old.example.test');
        $link = URL::temporarySignedRoute('guest.orders.show', now()->addDay(), ['order' => $order->id]);
        URL::forceRootUrl(null);
        $this->get($link)->assertOk()->assertSee('ZOT-LEGACY-LINK');
        $this->get($link.'changed')->assertForbidden()->assertDontSee('ZOT-LEGACY-LINK');
        Http::assertNothingSent();
    }

    public function test_legacy_callback_keeps_signature_verification_and_does_not_accept_forged_payment(): void
    {
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_canonical', 'shop.paymongo.webhook_secret' => 'canonical-secret']);
        $body = ['data' => ['id' => 'evt_canonical_test', 'attributes' => ['type' => 'test.unhandled', 'livemode' => false]]];
        $json = json_encode($body);
        $time = (string) time();
        $signature = hash_hmac('sha256', $time.'.'.$json, 'canonical-secret');
        $this->call('POST', 'https://old.example.test/webhooks/paymongo', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => 't='.$time.',te='.$signature], $json)
            ->assertOk()->assertJsonPath('received', true);
        $this->postJson('https://old.example.test/webhooks/paymongo', $body)->assertUnauthorized();
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }
}
