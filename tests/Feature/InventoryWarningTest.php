<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function variant(int $stock, int $reserved = 0, int $threshold = 10, ?Product $product = null): Variant
    {
        $product ??= Product::create(['name' => 'Warning fixture '.Str::random(8), 'slug' => 'warning-'.Str::random(12),
            'description' => 'Isolated inventory warning fixture', 'category' => '', 'active' => true]);

        return $product->variants()->create(['sku' => 'WARNING-'.Str::random(12), 'size' => 'M', 'color' => 'White '.Str::random(8),
            'stock' => $stock, 'reserved' => $reserved, 'reorder_level' => $threshold,
            'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
    }

    private function warningRegion(string $html): string
    {
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertCount(0, $xpath->query('//*[@data-report-region="inventory-warnings"]'), 'The redundant top inventory warning banner stays removed.');
        $this->assertCount(1, $xpath->query('//*[@data-report-region="recent-inventory"]'), 'The lower inventory panel remains a stable live-refresh region.');
        $rows = $xpath->query('//*[@data-report-region="recent-inventory"]//*[contains(concat(" ", normalize-space(@class), " "), " inventory-attention-row ")]');

        return collect($rows)->map(fn ($row) => $document->saveHTML($row))->implode('');
    }

    public static function stockLevels(): array
    {
        return [
            'out of stock' => [0, 0, 10, 'critical'],
            'half threshold' => [5, 0, 10, 'critical'],
            'above half' => [6, 0, 10, 'alert'],
            'at threshold' => [10, 0, 10, 'alert'],
            'healthy' => [11, 0, 10, 'healthy'],
            'reserved leaves critical' => [12, 8, 10, 'critical'],
            'reserved leaves alert' => [12, 2, 10, 'alert'],
            'all stock reserved' => [12, 12, 10, 'critical'],
            'odd threshold lower half' => [2, 0, 5, 'critical'],
            'odd threshold upper half' => [3, 0, 5, 'alert'],
            'threshold one empty' => [0, 0, 1, 'critical'],
            'threshold one stocked' => [1, 0, 1, 'alert'],
            'threshold zero empty' => [0, 0, 0, 'critical'],
            'threshold zero stocked' => [1, 0, 0, 'healthy'],
        ];
    }

    #[DataProvider('stockLevels')]
    public function test_dashboard_warnings_follow_available_variant_stock_and_its_own_threshold(int $stock, int $reserved, int $threshold, string $severity): void
    {
        $variant = $this->variant($stock, $reserved, $threshold);
        $before = $variant->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $expected = ['critical' => (int) ($severity === 'critical'), 'alert' => (int) ($severity === 'alert')];
        $response = $this->get('/admin')->assertOk()->assertViewHas('inventoryWarnings', $expected);
        $region = $this->warningRegion($response->getContent());
        if ($severity === 'healthy') {
            $this->assertSame('', $region);
        } else {
            $label = $severity === 'critical' ? 'Critical low' : 'Alert';
            $this->assertStringContainsString($label, $region);
            $this->assertStringContainsString('inventory-status-'.$severity, $region);
        }
        $this->assertSame($before, $variant->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public static function workspaceRoles(): array
    {
        return [['admin'], ['staff']];
    }

    #[DataProvider('workspaceRoles')]
    public function test_warning_counts_all_affected_variants_beyond_the_six_row_preview(string $role): void
    {
        $first = $this->variant(2);
        for ($index = 0; $index < 7; $index++) {
            $this->variant(4, 0, 10, $first->product);
        }
        for ($index = 0; $index < 9; $index++) {
            $this->variant(8, 0, 10, $first->product);
        }
        $this->variant(11, 0, 10, $first->product);
        $this->actingAs(User::factory()->create(['role' => $role]));
        $response = $this->get('/admin')->assertOk()
            ->assertViewHas('lowStockCount', 17)
            ->assertViewHas('lowStock', fn ($rows) => $rows->count() === 6)
            ->assertViewHas('inventoryWarnings', ['critical' => 8, 'alert' => 9])
            ->assertSeeInOrder(['Needs attention', 'Confirmed Clothing sale', 'Inventory attention'])
            ->assertDontSee('Inventory needs attention')->assertSee('View all 17 shortages');
        $region = $this->warningRegion($response->getContent());
        $this->assertSame(6, substr_count($region, 'inventory-attention-row'));
        $this->assertStringContainsString('inventory-status-critical', $region);
        $this->assertStringNotContainsString('inventory-status-alert', $region);
        $this->get(route('admin.inventory-attention'))->assertOk()->assertViewHas('shortages', fn ($rows) => $rows->total() === 17);
        Http::assertNothingSent();
    }

    public function test_hidden_archived_products_and_archived_variants_do_not_create_active_stock_warnings(): void
    {
        $hidden = $this->variant(0);
        $hidden->product->update(['active' => false]);
        $archivedProduct = $this->variant(0);
        $archivedProduct->product->update(['archived_at' => now()]);
        $archivedVariant = $this->variant(0);
        $archivedVariant->update(['archived_at' => now()]);
        $this->variant(30);
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $response = $this->get('/admin')->assertOk()->assertViewHas('inventoryWarnings', ['critical' => 0, 'alert' => 0]);
        $this->assertSame('', $this->warningRegion($response->getContent()));
        Http::assertNothingSent();
    }

    public function test_empty_catalog_keeps_an_empty_stable_refresh_region_without_a_warning(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->get('/admin')->assertOk()->assertViewHas('inventoryWarnings', ['critical' => 0, 'alert' => 0]);
        $this->assertSame('', $this->warningRegion($response->getContent()));
    }

    public function test_live_reports_can_add_change_and_remove_warnings_after_stock_changes(): void
    {
        $variant = $this->variant(20);
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $refresh = fn () => $this->getJson('/admin', ['X-Report-Refresh' => '1'])->assertOk()->json('html');
        $this->assertSame('', $this->warningRegion($refresh()));

        $variant->update(['reserved' => 16]);
        $this->assertStringContainsString('inventory-status-critical', $this->warningRegion($refresh()));
        $variant->update(['reserved' => 12]);
        $region = $this->warningRegion($refresh());
        $this->assertStringContainsString('inventory-status-alert', $region);
        $this->assertStringNotContainsString('inventory-status-critical', $region);
        $variant->update(['reserved' => 0]);
        $this->assertSame('', $this->warningRegion($refresh()));
        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_guests_and_customers_cannot_access_internal_warning_data(): void
    {
        $variant = $this->variant(0);
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->getJson('/admin', ['X-Report-Refresh' => '1'])->assertUnauthorized();
        $this->get('/')->assertOk()->assertDontSee('inventory-warning-heading', false);
        $this->get(route('product', $variant->product))->assertOk()->assertDontSee('inventory-warning-heading', false);
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $this->get('/admin')->assertForbidden();
        $this->getJson('/admin', ['X-Report-Refresh' => '1'])->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee('inventory-warning-heading', false);
        Http::assertNothingSent();
    }

    public static function ownerWorkspacePages(): array
    {
        return [['/admin'], ['/admin/orders'], ['/admin/settings'], ['/admin/products'], ['/admin/forecast'], ['/account']];
    }

    #[DataProvider('ownerWorkspacePages')]
    public function test_owner_has_stock_notifications_across_the_workspace(string $url): void
    {
        $this->variant(2);
        $this->variant(8);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->get($url)->assertOk()
            ->assertSee('id="inventory-notifications"', false)
            ->assertSee('Inventory notifications: 2 affected variants')
            ->assertSee('Stock notifications')
            ->assertSee('inventory-warnings.css', false)
            ->assertSee('These are current stock counts, not unread messages.')
            ->assertSee('Opening this menu does not clear warnings.')
            ->assertSee(route('admin.inventory-attention'), false);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $notifications = (new \DOMXPath($document))->query('//*[@id="inventory-notifications"]');
        $this->assertCount(1, $notifications);
        $text = $notifications->item(0)->textContent;
        $this->assertStringContainsString('Critical low', $text);
        $this->assertStringContainsString('Alert', $text);
        Http::assertNothingSent();
    }

    public function test_dashboard_header_and_inventory_panel_share_one_aggregate_query_and_refresh_on_the_next_request(): void
    {
        $variant = $this->variant(8);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/admin')->assertOk()->assertViewHas('inventoryWarnings', ['critical' => 0, 'alert' => 1])
            ->assertSee('Inventory notifications: 1 affected variant');
        $aggregateQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'as critical'));
        $this->assertCount(1, $aggregateQueries, 'Dashboard and header reuse the same request snapshot rather than loading every variant or querying twice.');

        $variant->update(['stock' => 2]);
        DB::flushQueryLog();
        $this->get('/admin')->assertOk()->assertViewHas('inventoryWarnings', ['critical' => 1, 'alert' => 0])
            ->assertSee('Critical low')->assertSee('Inventory notifications: 1 affected variant');
        $this->assertCount(1, collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'as critical')));
        DB::disableQueryLog();
        Http::assertNothingSent();
    }

    public function test_staff_and_public_customer_pages_have_no_owner_notification_menu_or_notification_query(): void
    {
        $this->variant(0);
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $this->get('/admin')->assertOk()->assertSee('Inventory attention')->assertSee('Critical low')
            ->assertDontSee('Inventory needs attention')
            ->assertDontSee('id="inventory-notifications"', false);
        foreach (['admin', 'customer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get('/')->assertOk()->assertDontSee('id="inventory-notifications"', false);
            $this->assertCount(0, collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'as critical')));
            DB::disableQueryLog();
        }
        Http::assertNothingSent();
    }

    public function test_owner_notification_menu_is_clear_when_no_stock_warnings_exist(): void
    {
        $this->variant(25);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/settings')->assertOk()
            ->assertSee('Inventory notifications: 0 affected variants')
            ->assertSee('No active stock warnings.')
            ->assertDontSee('class="inventory-notification-count"', false);
    }
}
