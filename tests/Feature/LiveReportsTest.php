<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Forecast;
use App\Services\InvoicePayments;
use App\Services\OrderFlow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiveReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-13 12:00:00', 'Asia/Manila'));
        Http::preventStrayRequests();
    }

    public static function workspacePages(): array
    {
        return [['/admin', 'admin'], ['/admin', 'staff'], ['/admin/forecast', 'admin'], ['/admin/forecast', 'staff']];
    }

    public static function pages(): array
    {
        return [['/admin'], ['/admin/forecast']];
    }

    private function refresh(string $url)
    {
        return $this->getJson($url, ['X-Report-Refresh' => '1']);
    }

    private function total(string $html): string
    {
        $matched = preg_match('/<[^>]+\bdata-report-total(?:="[^"]*")?[^>]*>(.*?)<\/[^>]+>/su', $html, $match);
        $this->assertSame(1, $matched, 'The actual sales total should remain identifiable independently of forecast and invoice figures.');

        return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function reservedOrder(int $quantity = 2): Order
    {
        $product = Product::create(['name' => 'Live report tee', 'slug' => 'live-report-'.Str::random(10),
            'description' => 'Isolated report fixture', 'category' => '', 'active' => true,
            'created_at' => '2026-08-01 09:00:00']);
        $variant = $product->variants()->create(['sku' => 'LIVE-'.Str::random(12), 'size' => 'M', 'color' => 'White',
            'stock' => 100, 'reserved' => 0, 'reorder_level' => 5,
            'retail_price' => 12345, 'wholesale_price' => 11000, 'reseller_price' => 10000]);

        return app(OrderFlow::class)->reserve([$variant->id => $quantity], ['recipient' => 'Report buyer',
            'phone' => '09123456789', 'address' => 'Isolated delivery destination',
            'delivery_method' => 'lalamove', 'shipping' => 4400],
            User::factory()->create(['role' => 'customer'])->id);
    }

    private function confirm(Order $order, ?string $paidAt = null): void
    {
        // Simulate the trusted result of provider verification, using the same
        // locked transition that commits inventory for a confirmed payment.
        DB::transaction(function () use ($order, $paidAt) {
            app(OrderFlow::class)->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(),
                'pay_live_report_'.Str::random(12), $paidAt ? Carbon::parse($paidAt, 'Asia/Manila') : null);
        });
    }

    #[DataProvider('workspacePages')]
    public function test_normal_pages_keep_html_and_offer_the_actual_sales_report(string $url, string $role): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => $role]))
            ->get($url)->assertOk()->assertSee('Total Sales')->assertSee('data-report-live', false);
        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        Http::assertNothingSent();
    }

    #[DataProvider('workspacePages')]
    public function test_refresh_has_private_json_and_rendered_read_only_regions(string $url, string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $response = $this->refresh($url)->assertOk()->assertJsonStructure(['html', 'checked_at']);
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('data-report-region', $response->json('html'));
        $this->assertSame('₱0.00', $this->total($response->json('html')));
        $this->assertTrue(Carbon::parse($response->json('checked_at'))->equalTo(now()));
        Http::assertNothingSent();
    }

    #[DataProvider('pages')]
    public function test_refresh_header_alone_preserves_normal_html_navigation(string $url): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get($url, ['X-Report-Refresh' => '1', 'Accept' => 'text/html'])->assertOk();
        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
    }

    #[DataProvider('pages')]
    public function test_guests_and_customers_cannot_read_workspace_refresh_data(string $url): void
    {
        $this->refresh($url)->assertUnauthorized()->assertJsonMissingPath('html');
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $this->refresh($url)->assertForbidden()->assertJsonMissingPath('html');
        Http::assertNothingSent();
    }

    #[DataProvider('workspacePages')]
    public function test_refresh_keeps_email_verification_required(string $url, string $role): void
    {
        config(['auth.email_verification.required' => true]);
        $this->actingAs(User::factory()->unverified()->create(['role' => $role]));
        $this->refresh($url)->assertForbidden()->assertJsonMissingPath('html');
        Http::assertNothingSent();
    }

    #[DataProvider('pages')]
    public function test_refresh_counts_confirmed_clothing_once_and_excludes_shipping(string $url): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $order = $this->reservedOrder();
        $url .= '?period=daily&start_date=2026-11-13&end_date=2026-11-13';
        $this->assertSame('₱0.00', $this->total($this->refresh($url)->assertOk()->json('html')));
        $variant = $order->items()->sole()->variant;
        $this->assertSame(100, $variant->stock);
        $this->assertSame(2, $variant->reserved);

        $this->confirm($order);
        $this->assertSame('₱246.90', $this->total($this->refresh($url)->assertOk()->json('html')));
        $this->assertSame('₱246.90', $this->total($this->refresh($url)->assertOk()->json('html')));
        $this->assertSame(98, $variant->fresh()->stock);
        $this->assertSame(0, $variant->fresh()->reserved);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_current_month_payment_updates_actuals_without_retraining_completed_month_forecasts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['2026-08-15 10:00:00', '2026-09-15 10:00:00', '2026-10-15 10:00:00'] as $index => $date) {
            $this->confirm($this->reservedOrder($index + 1), $date);
        }
        $forecastBefore = app(Forecast::class)->revenue();
        $url = '/admin/forecast?period=daily&start_date=2026-11-13&end_date=2026-11-13';
        $before = $this->refresh($url)->assertOk()->json('html');
        $this->assertSame('₱0.00', $this->total($before));
        $this->confirm($this->reservedOrder());
        $after = $this->refresh($url)->assertOk()->json('html');
        $this->assertSame('₱246.90', $this->total($after));
        $this->assertSame($forecastBefore, app(Forecast::class)->revenue());
        preg_match_all('/<span class="forecast-chart-total">(.*?)<\/span>/su', $before, $beforeNumbers);
        preg_match_all('/<span class="forecast-chart-total">(.*?)<\/span>/su', $after, $afterNumbers);
        $this->assertCount(2, $beforeNumbers[1]);
        $this->assertSame(['₱555.53', '₱493.80'], array_map('trim', $beforeNumbers[1]));
        $this->assertSame($beforeNumbers[1], $afterNumbers[1]);

        $october = $this->refresh('/admin/forecast?period=monthly&start_date=2026-10-01&end_date=2026-10-31')
            ->assertOk()->json('html');
        $this->assertSame('₱370.35', $this->total($october));
        $this->assertSame($forecastBefore, app(Forecast::class)->revenue());
        Http::assertNothingSent();
    }

    public function test_a_confirmed_walk_in_cash_receipt_updates_the_same_live_sales_total(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff);
        $order = $this->reservedOrder();
        $order->update(['sales_channel' => 'walk_in', 'user_id' => null]);
        $this->assertSame('₱0.00', $this->total($this->refresh('/admin')->assertOk()->json('html')));

        app(InvoicePayments::class)->recordCash($order, $order->total, $staff,
            'SIMULATED TEST — cash payment received at the counter.', (string) Str::uuid());

        $this->assertSame('₱246.90', $this->total($this->refresh('/admin')->assertOk()->json('html')));
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertDatabaseHas('payment_attempts', ['order_id' => $order->id, 'method' => 'cash',
            'status' => 'paid', 'amount' => 29090]);
        Http::assertNothingSent();
    }

    public static function invalidFilters(): array
    {
        return [['period=hourly', 'period'], ['period%5B%5D=daily', 'period'],
            ['start_date=2026-11-13', 'end_date'],
            ['start_date=2026-11-13&end_date=2026-11-12', 'end_date'],
            ['start_date=2026-11-14&end_date=2026-11-14', 'start_date']];
    }

    #[DataProvider('invalidFilters')]
    public function test_refresh_rejects_invalid_dates_and_grouping_with_readable_json(string $query, string $field): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        foreach (['/admin', '/admin/forecast'] as $url) {
            $this->refresh($url.'?'.$query)->assertStatus(422)->assertJsonValidationErrors($field)
                ->assertJsonMissingPath('html');
        }
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }
}
