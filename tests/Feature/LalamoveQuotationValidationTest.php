<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\StructuredAddress;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LalamoveQuotationValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-26 14:00:00', 'Asia/Manila'));
        config(['auth.email_verification.required' => false, 'shop.lalamove.mode' => 'sandbox',
            'shop.lalamove.key' => 'pk_test_quotation_fixture', 'shop.lalamove.secret' => 'sk_test_quotation_fixture',
            'shop.lalamove.address' => 'Synthetic pickup', 'shop.lalamove.lat' => '14.5', 'shop.lalamove.lng' => '121',
            'shop.lalamove.phone' => '09123456789', 'shop.lalamove.service' => 'MOTORCYCLE',
            'shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_payment_fixture',
            'shop.paymongo.webhook_secret' => 'mock-webhook-secret']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function address(): array
    {
        return ['street_address' => '12 Example Street', 'city' => 'Quezon City', 'province' => 'Metro Manila',
            'postal_code' => '1100', 'country' => 'Philippines', 'lat' => '14.6', 'lng' => '121.1'];
    }

    private function quotation(): array
    {
        return ['quotationId' => 'quote_validation_fixture', 'expiresAt' => now()->addMinutes(5)->toIso8601String(),
            'stops' => [['stopId' => 'pickup'], ['stopId' => 'recipient']], 'priceBreakdown' => ['currency' => 'PHP', 'total' => '125.50']];
    }

    public static function invalidResponses(): array
    {
        return [['missing_expiry'], ['malformed_expiry'], ['relative_expiry'], ['expired'], ['expiry_boundary'],
            ['missing_id'], ['invalid_id'], ['missing_stops'], ['duplicate_stops'], ['invalid_stop'],
            ['currency'], ['negative_price'], ['nonfinite_price'], ['overflow_price'], ['nonnumeric_price']];
    }

    private function invalidQuotation(string $case): array
    {
        $quote = $this->quotation();
        match ($case) {
            'missing_expiry' => $quote['expiresAt'] = null,
            'malformed_expiry' => $quote['expiresAt'] = ['invalid'],
            'relative_expiry' => $quote['expiresAt'] = 'tomorrow',
            'expired' => $quote['expiresAt'] = now()->subSecond()->toIso8601String(),
            'expiry_boundary' => $quote['expiresAt'] = now()->toIso8601String(),
            'missing_id' => $quote['quotationId'] = null,
            'invalid_id' => $quote['quotationId'] = 'https://example.test/quotation',
            'missing_stops' => $quote['stops'] = [],
            'duplicate_stops' => $quote['stops'][1]['stopId'] = 'pickup',
            'invalid_stop' => $quote['stops'][1]['stopId'] = ['invalid'],
            'currency' => $quote['priceBreakdown']['currency'] = 'USD',
            'negative_price' => $quote['priceBreakdown']['total'] = '-1',
            'nonfinite_price' => $quote['priceBreakdown']['total'] = '1e500',
            'overflow_price' => $quote['priceBreakdown']['total'] = '42949672.96',
            'nonnumeric_price' => $quote['priceBreakdown']['total'] = 'invalid',
        };
        if ($case === 'missing_expiry') {
            unset($quote['expiresAt']);
        }

        return $quote;
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_provider_quotation_is_recoverable_and_clears_previous_session_quote(string $case): void
    {
        Http::fake(['*/v3/quotations' => Http::response(['data' => $this->invalidQuotation($case)], 201)]);
        $this->withSession(['delivery_quote' => ['quote' => $this->quotation()]])
            ->postJson('/checkout/quote', $this->address())->assertStatus(503)
            ->assertJsonPath('message', 'Delivery quotation is unavailable. Check the address and try again, or choose pickup.')
            ->assertSessionMissing('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_cached_quotation_cannot_start_payment_or_reserve_stock(string $case): void
    {
        $product = Product::create(['name' => 'Quotation fixture tee', 'slug' => 'quotation-fixture-tee', 'category' => '', 'description' => 'Isolated fixture']);
        $variant = $product->variants()->create(['sku' => 'QUOTE-WHITE-M', 'size' => 'M', 'color' => 'White', 'stock' => 5,
            'retail_price' => 20000, 'wholesale_price' => 18000, 'reseller_price' => 16000]);
        $this->withSession(['cart' => [$variant->id => 1]]);
        $this->get('/checkout')->assertOk();
        $address = $this->address();
        $this->withSession(['delivery_quote' => ['quote' => $this->invalidQuotation($case),
            'address' => app(StructuredAddress::class)->format($address), 'lat' => $address['lat'], 'lng' => $address['lng']]])
            ->post('/checkout', $address + ['recipient' => 'Synthetic buyer', 'phone' => '09123456789', 'delivery_method' => 'lalamove'])
            ->assertSessionHasErrors('delivery')->assertSessionMissing('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->reserved);
        $this->assertSame([$variant->id => 1], session('cart'));
        Http::assertNothingSent();
    }

    public function test_valid_quote_retains_exact_address_and_expiry_without_following_redirects(): void
    {
        $quote = $this->quotation();
        $redirects = null;
        Http::fake(function ($request, $options) use ($quote, &$redirects) {
            $redirects = $options['allow_redirects'] ?? null;

            return Http::response(['data' => $quote], 201);
        });
        $this->postJson('/checkout/quote', $this->address())->assertOk()->assertJsonPath('amount', 12550)
            ->assertJsonPath('expires_at', $quote['expiresAt'])->assertSessionHas('delivery_quote.quote', $quote)
            ->assertSessionHas('delivery_quote.address', app(StructuredAddress::class)->format($this->address()));
        $this->assertFalse($redirects);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['data']['language'] === 'en_PH' && $request['data']['serviceType'] === 'MOTORCYCLE');
    }

    public static function nonSuccessStatuses(): array
    {
        return [[300], [301], [302], [303], [304], [307], [308]];
    }

    #[DataProvider('nonSuccessStatuses')]
    public function test_non_success_http_cannot_supply_a_plausible_quotation(int $status): void
    {
        Http::fake(['*/v3/quotations' => Http::response(['data' => $this->quotation()], $status,
            ['Location' => 'https://redirect.example.test/quotation'])]);
        $this->withSession(['delivery_quote' => ['quote' => $this->quotation()]])
            ->postJson('/checkout/quote', $this->address())->assertStatus(503)
            ->assertSessionMissing('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertSentCount(1);
    }

    public function test_transport_failure_clears_previous_quote_and_preserves_pickup_option(): void
    {
        Http::fake(fn () => throw new ConnectionException('Private provider diagnostic'));
        $this->withSession(['delivery_quote' => ['quote' => $this->quotation()]])
            ->postJson('/checkout/quote', $this->address())->assertStatus(503)
            ->assertDontSee('Private provider diagnostic')->assertSessionMissing('delivery_quote');
        $this->get('/checkout')->assertRedirect(route('cart'));
    }
}
