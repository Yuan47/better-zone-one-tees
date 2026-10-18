<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Forecast;
use App\Services\SalesReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'customer'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function order(string $date, int $subtotal, string $payment = 'paid', int $quantity = 1): Order
    {
        return Order::create([
            'number' => 'REPORT-'.uniqid(), 'user_id' => $this->user()->id, 'tier' => 'bulk', 'quantity' => $quantity,
            'merchandise_base' => $subtotal + 500, 'discount' => 500, 'subtotal' => $subtotal, 'shipping' => 15000,
            'total' => $subtotal + 15000, 'payment_status' => $payment, 'paid_at' => $date,
            'status' => 'completed', 'recipient' => 'Buyer', 'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup',
        ]);
    }

    private function product(string $name, string $created): Product
    {
        return Product::create(['name' => $name, 'slug' => 'report-'.uniqid(), 'category' => '', 'description' => 'Test clothing', 'created_at' => $created]);
    }

    private function variant(Product $product, array $overrides = [])
    {
        return $product->variants()->create($overrides + [
            'sku' => 'REPORT-'.uniqid(), 'size' => 'M', 'color' => 'White', 'stock' => 10, 'reserved' => 0,
            'reorder_level' => 10, 'retail_price' => 20000, 'wholesale_price' => 18000, 'reseller_price' => 17000,
        ]);
    }

    private function item(Order $order, $variant, int $quantity, ?string $name = null)
    {
        return $order->items()->create([
            'variant_id' => $variant->id, 'name' => $name ?? $variant->product->name,
            'size' => $variant->size, 'color' => $variant->color, 'quantity' => $quantity,
            'unit_price' => 100, 'total' => 100 * $quantity,
        ]);
    }

    public function test_daily_chart_uses_paid_at_net_clothing_and_zero_filled_days(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $this->order('2026-10-04 23:59:59', 10000);
        $this->order('2026-10-05 00:00:00', 12345);
        $this->order('2026-10-10 09:00:00', 20000);
        $this->order('2026-10-10 10:00:00', 30000);
        $this->order('2026-10-11 10:00:00', 99999, 'pending');
        $this->order('2026-10-11 10:00:00', 99999, 'refunded');
        $future = $this->order('2026-10-11 12:01:00', 77777);
        $future->update(['created_at' => '2026-10-05 00:00:00']);

        $chart = app(SalesReport::class)->chart();
        $this->assertCount(7, $chart['rows']);
        $this->assertSame('2026-10-05', $chart['rows'][0]['start']);
        $this->assertSame([12345, 0, 0, 0, 0, 50000, 0], array_column($chart['rows'], 'amount'));
        $this->assertSame(62345, $chart['total']);
        $this->assertSame(3, $chart['orders']);
        $this->assertTrue($chart['rows'][6]['current']);
        $this->travelBack();
    }

    public function test_weeks_start_monday_and_keep_cross_year_sales_in_correct_bucket(): void
    {
        $this->travelTo(Carbon::parse('2027-01-05 12:00:00', 'Asia/Manila'));
        $this->order('2026-11-15 23:59:59', 90000);
        $this->order('2026-11-16 00:00:00', 1000);
        $this->order('2027-01-03 23:59:59', 12345);
        $this->order('2027-01-04 00:00:00', 20000);

        $chart = app(SalesReport::class)->chart('weekly');
        $this->assertCount(8, $chart['rows']);
        $this->assertSame('2026-11-16', $chart['rows'][0]['start']);
        $this->assertSame(1000, $chart['rows'][0]['amount']);
        $this->assertSame('2026-12-28', $chart['rows'][6]['start']);
        $this->assertSame(12345, $chart['rows'][6]['amount']);
        $this->assertSame('2027-01-04', $chart['rows'][7]['start']);
        $this->assertSame(20000, $chart['rows'][7]['amount']);
        $this->travelBack();
    }

    public function test_monthly_and_yearly_period_boundaries_are_bounded_and_include_current_period(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $this->order('2021-12-31 23:59:59', 99999);
        $this->order('2022-01-01 00:00:00', 1000);
        $this->order('2025-10-31 23:59:59', 2000);
        $this->order('2025-11-01 00:00:00', 3000);
        $this->order('2026-09-30 23:59:59', 4000);
        $this->order('2026-10-01 00:00:00', 5000);

        $monthly = app(SalesReport::class)->chart('monthly');
        $yearly = app(SalesReport::class)->chart('yearly');
        $this->assertCount(12, $monthly['rows']);
        $this->assertSame('2025-11-01', $monthly['rows'][0]['start']);
        $this->assertSame(3000, $monthly['rows'][0]['amount']);
        $this->assertSame(4000, $monthly['rows'][10]['amount']);
        $this->assertSame(5000, $monthly['rows'][11]['amount']);
        $this->assertSame(12000, $monthly['total']);
        $this->assertCount(5, $yearly['rows']);
        $this->assertSame('2022-01-01', $yearly['rows'][0]['start']);
        $this->assertSame([1000, 0, 0, 5000, 9000], array_column($yearly['rows'], 'amount'));
        $this->assertSame(15000, $yearly['total']);
        $this->travelBack();
    }

    public function test_chart_business_day_is_asia_manila_even_when_clock_is_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 16:15:00', 'UTC'));
        $this->order('2026-10-11 23:59:59', 1000);
        $this->order('2026-10-12 00:01:00', 2000);
        $chart = app(SalesReport::class)->chart();
        $this->assertSame('2026-10-12', $chart['rows'][6]['start']);
        $this->assertSame(1000, $chart['rows'][5]['amount']);
        $this->assertSame(2000, $chart['rows'][6]['amount']);
        $this->travelBack();
    }

    public function test_admin_and_staff_can_open_period_views_and_forecast_is_separate(): void
    {
        $this->order('2025-01-01 12:00:00', 12345);
        foreach (['admin', 'staff'] as $role) {
            $this->actingAs($this->user($role));
            foreach (SalesReport::PERIODS as $period) {
                $this->get('/admin?period='.$period)->assertOk()
                    ->assertSee('Confirmed Clothing sale')->assertSee('Sales over time')
                    ->assertDontSee('Category demand')->assertDontSee('Sales by category')
                    ->assertViewHas('period', $period)->assertViewHas('revenue', 12345)
                    ->assertViewHas('ordersCount', 1)->assertDontSee('Previous period sales ×');
            }
            $this->get('/admin/forecast')->assertOk()->assertSee('Historical units')
                ->assertSee('Previous period sales ×')->assertSee('y = a + bx')
                ->assertDontSee('Category demand')->assertViewIs('admin.forecast');
        }
    }

    public function test_report_access_requires_staff_or_admin_and_rejects_invalid_period(): void
    {
        $this->get('/admin/forecast')->assertRedirect(route('login'));
        $this->actingAs($this->user())->get('/admin')->assertForbidden();
        $this->get('/admin/forecast')->assertForbidden();
        $this->actingAs($this->user('staff'))->getJson('/admin?period=hourly')->assertUnprocessable();
    }

    public function test_forecast_counts_product_units_across_variants_without_category_and_ignores_inactive_products(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $p = Product::create(['name' => 'Live Tee', 'slug' => 'live-tee', 'category' => 'Legacy', 'description' => 'Tee']);
        $v1 = $p->variants()->create(['sku' => 'REPORT-WHITE-M', 'size' => 'M', 'color' => 'White', 'stock' => 5, 'reserved' => 1, 'retail_price' => 20000, 'wholesale_price' => 18000, 'reseller_price' => 17000]);
        $v2 = $p->variants()->create(['sku' => 'REPORT-RED-L', 'size' => 'L', 'color' => 'Red', 'stock' => 6, 'reserved' => 0, 'retail_price' => 20000, 'wholesale_price' => 18000, 'reseller_price' => 17000]);
        Product::create(['name' => 'Archived Tee', 'slug' => 'archived-tee', 'category' => 'Legacy', 'description' => 'Tee', 'active' => false]);
        foreach ([['2026-07-01', 2, 3], ['2026-08-01', 4, 6], ['2026-09-01', 6, 9], ['2026-10-01', 20, 30]] as [$date, $q1, $q2]) {
            $order = $this->order($date.' 12:00:00', 10000, 'paid', $q1 + $q2);
            foreach ([[$v1, $q1], [$v2, $q2]] as [$variant, $quantity]) {
                $order->items()->create(['variant_id' => $variant->id, 'name' => $p->name, 'size' => $variant->size, 'color' => $variant->color, 'quantity' => $quantity, 'unit_price' => 100, 'total' => 100 * $quantity]);
            }
        }

        $rows = app(Forecast::class)->rows();
        $this->assertCount(1, $rows);
        $this->assertSame([5, 10, 15], $rows[0]['values']);
        $this->assertSame(30, $rows[0]['sold']);
        $this->assertSame(20, $rows[0]['forecast']);
        $this->assertSame(10, $rows[0]['available']);
        $this->assertSame(10, $rows[0]['reorder']);
        $this->assertTrue($rows[0]['observed_history']);
        $this->assertSame('Earlier verified paid sales', $rows[0]['history_basis']);
        $this->assertArrayNotHasKey('category', $rows[0]);
        $this->travelBack();
    }

    public function test_new_product_does_not_inherit_other_products_pre_introduction_months(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $established = $this->product('Established Tee', '2026-07-01 09:00:00');
        $establishedVariant = $this->variant($established);
        foreach (['2026-07-15', '2026-08-15', '2026-09-15'] as $date) {
            $this->item($this->order($date.' 12:00:00', 10000), $establishedVariant, 100);
        }
        $new = $this->product('September Tee', '2026-09-01 09:00:00');
        $newVariant = $this->variant($new);
        $this->item($this->order('2026-09-20 12:00:00', 6000), $newVariant, 60);
        $this->item($this->order('2026-10-01 12:00:00', 99900), $newVariant, 999);

        $row = collect(app(Forecast::class)->rows())->firstWhere('name', 'September Tee');
        $this->assertSame(['2026-09'], $row['months']);
        $this->assertSame([60], $row['values']);
        $this->assertSame(60, $row['sold']);
        $this->assertTrue($row['observed_history']);
        $this->assertNull($row['forecast']);
        $this->assertNull($row['growth']['prediction']);
        $this->assertNull($row['reorder']);
        $this->actingAs($this->user('admin'))->get('/admin/forecast')->assertOk()
            ->assertSee('1 completed month')->assertSee('Months before that are excluded.')
            ->assertSee('September Tee · history and calculation inputs');
        $this->travelBack();
    }

    public function test_unsold_product_needs_observed_history_and_eligible_zero_sale_months_are_retained(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $unsold = $this->product('Unsold Tee', '2026-07-01 09:00:00');
        $this->variant($unsold);
        $established = $this->product('Established Tee', '2026-07-01 09:00:00');
        $variant = $this->variant($established);
        $this->item($this->order('2026-07-10 12:00:00', 1000), $variant, 10);
        $this->item($this->order('2026-09-10 12:00:00', 3000), $variant, 30);
        $rows = collect(app(Forecast::class)->rows());
        $unsoldRow = $rows->firstWhere('name', 'Unsold Tee');
        $this->assertSame(['2026-07', '2026-08', '2026-09'], $unsoldRow['months']);
        $this->assertSame([0, 0, 0], $unsoldRow['values']);
        $this->assertFalse($unsoldRow['observed_history']);
        $this->assertNull($unsoldRow['forecast']);
        $this->assertNull($unsoldRow['regression']['a']);
        $this->assertNull($unsoldRow['growth']['prediction']);
        $this->assertNull($unsoldRow['reorder']);
        $establishedRow = $rows->firstWhere('name', 'Established Tee');
        $this->assertSame([10, 0, 30], $establishedRow['values']);
        $this->assertSame(40, $establishedRow['sold']);
        $this->assertNotNull($establishedRow['forecast']);
        $this->actingAs($this->user('staff'))->get('/admin/forecast')->assertOk()
            ->assertSee('No verified paid pieces in a completed month yet; zero demand is not assumed.');
        $this->travelBack();
    }

    public function test_best_sellers_survive_renames_and_keep_same_named_products_separate(): void
    {
        $product = $this->product('Original Tee', '2026-01-01 09:00:00');
        $variant = $this->variant($product);
        $earlierItem = $this->item($this->order('2026-09-01 12:00:00', 3000), $variant, 3);
        $product->update(['name' => 'Renamed Tee']);
        $this->item($this->order('2026-09-02 12:00:00', 5000), $variant->fresh(), 5);
        $sameNamed = $this->product('Renamed Tee', '2026-01-01 09:00:00');
        $this->item($this->order('2026-09-03 12:00:00', 2000), $this->variant($sameNamed), 2);
        $this->item($this->order('2026-09-04 12:00:00', 99900, 'pending'), $variant, 99);

        $response = $this->actingAs($this->user('admin'))->get('/admin')->assertOk();
        $best = $response->viewData('best');
        $this->assertCount(2, $best);
        $this->assertSame($product->id, (int) $best[0]->product_id);
        $this->assertSame('Renamed Tee', $best[0]->name);
        $this->assertSame(8, (int) $best[0]->units);
        $this->assertSame($sameNamed->id, (int) $best[1]->product_id);
        $this->assertSame(2, (int) $best[1]->units);
        $this->assertSame('Original Tee', $earlierItem->fresh()->name);
    }

    public function test_inventory_attention_prioritizes_urgent_variants_and_lists_all_shortages_with_filters(): void
    {
        $product = $this->product('Standard Tee', '2026-01-01 09:00:00');
        for ($i = 0; $i < 35; $i++) {
            $this->variant($product, ['sku' => 'NORMAL-'.$i, 'color' => 'Shade '.$i, 'stock' => 5 + $i % 3]);
        }
        $priority = $this->product('Priority Tee', '2026-01-01 09:00:00');
        $zero = $this->variant($priority, ['sku' => 'URGENT-HELD', 'color' => 'Blue', 'stock' => 1, 'reserved' => 1, 'reorder_level' => 20]);
        $urgent = $this->variant($priority, ['sku' => 'URGENT-ZERO', 'color' => 'Red', 'stock' => 0, 'reorder_level' => 40]);
        $low = $this->variant($priority, ['sku' => 'PRIORITY-LOW', 'color' => 'Gold', 'stock' => 2]);
        $this->variant($product, ['sku' => 'HEALTHY', 'color' => 'Black', 'stock' => 11]);
        $hidden = $this->product('Hidden Tee', '2026-01-01 09:00:00');
        $hidden->update(['active' => false]);
        $this->variant($hidden, ['sku' => 'HIDDEN-ZERO', 'stock' => 0]);
        $this->actingAs($this->user('admin'));
        $dashboard = $this->get('/admin')->assertOk()->assertSee('View all 38 shortages');
        $preview = $dashboard->viewData('lowStock');
        $this->assertCount(6, $preview);
        $this->assertSame([$urgent->id, $zero->id, $low->id], $preview->take(3)->pluck('id')->all());

        $first = $this->get('/admin/inventory-attention')->assertOk()->assertSee('38 matching variants');
        $firstPage = $first->viewData('shortages');
        $this->assertSame(38, $firstPage->total());
        $this->assertCount(30, $firstPage);
        $this->assertSame($urgent->id, $firstPage->first()->id);
        $second = $this->get('/admin/inventory-attention?page=2')->assertOk()->viewData('shortages');
        $this->assertCount(8, $second);
        $this->assertCount(38, $firstPage->getCollection()->concat($second->getCollection())->pluck('id')->unique());
        $this->assertSame(2, $this->get('/admin/inventory-attention?availability=out_of_stock')->assertOk()->viewData('shortages')->total());
        $this->assertSame(3, $this->get('/admin/inventory-attention?product='.$priority->id)->assertOk()->viewData('shortages')->total());
        $this->assertSame(2, $this->get('/admin/inventory-attention?q=URGENT')->assertOk()->viewData('shortages')->total());
        $this->assertSame(35, $this->get('/admin/inventory-attention?q=Standard')->assertOk()->viewData('shortages')->total());
        $this->getJson('/admin/inventory-attention?availability=unknown')->assertUnprocessable();
        $this->getJson('/admin/inventory-attention?product='.$hidden->id)->assertUnprocessable();
    }

    public function test_inventory_attention_is_readable_by_staff_but_not_customers(): void
    {
        $this->get('/admin/inventory-attention')->assertRedirect(route('login'));
        $this->actingAs($this->user())->get('/admin/inventory-attention')->assertForbidden();
        $this->variant($this->product('Low Tee', '2026-01-01 09:00:00'));
        $this->actingAs($this->user('staff'))->get('/admin/inventory-attention')->assertOk()->assertDontSee('Edit inventory');
        $this->actingAs($this->user('admin'))->get('/admin/inventory-attention')->assertOk()->assertSee('Edit inventory');
    }

    public static function explicitPeriods(): array
    {
        return [['daily', 3], ['weekly', 1], ['monthly', 2], ['yearly', 1]];
    }

    #[DataProvider('explicitPeriods')]
    public function test_explicit_range_includes_both_dates_and_clips_partial_buckets(string $period, int $buckets): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Manila'));
        $this->order('2026-09-29 23:59:59', 99999);
        $first = $this->order('2026-09-30 00:00:00', 10000);
        $first->update(['created_at' => '2026-10-10 10:00:00']);
        $this->order('2026-10-02 23:59:59', 30000);
        $this->order('2026-10-03 00:00:00', 99999);
        $this->order('2026-10-01 12:00:00', 99999, 'pending');
        $this->order('2026-10-01 12:00:00', 99999, 'refunded');
        $chart = app(SalesReport::class)->chart($period, '2026-09-30', '2026-10-02');
        $this->assertCount($buckets, $chart['rows']);
        $this->assertSame(40000, $chart['total']);
        $this->assertSame(2, $chart['orders']);
        $this->assertTrue($chart['custom_range']);
        $this->assertStringStartsWith('Sep 30, 2026', $chart['rows'][0]['range']);
        $this->assertStringEndsWith('Oct 2, 2026', $chart['rows'][$buckets - 1]['range']);
        $this->assertFalse(collect($chart['rows'])->contains('current', true));
        $this->assertSame($buckets, count($chart['plot']['points']));
        $this->assertMatchesRegularExpression('/^[0-9., ]+$/', $chart['plot']['line']);
        $this->travelBack();
    }

    public function test_selected_range_scopes_sales_cards_order_value_best_sellers_and_export_link(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Manila'));
        $selected = $this->product('Selected Tee', '2026-01-01 09:00:00');
        $selectedVariant = $this->variant($selected);
        $outsideVariant = $this->variant($this->product('Outside Tee', '2026-01-01 09:00:00'));
        $outside = $this->order('2026-09-29 23:59:59', 90000);
        $outside->update(['status' => 'to_pack']);
        $this->item($outside, $outsideVariant, 100);
        $this->item($this->order('2026-09-30 00:00:00', 10000), $selectedVariant, 2);
        $this->item($this->order('2026-10-02 23:59:59', 30000), $selectedVariant, 6);
        $this->item($this->order('2026-10-03 00:00:00', 99000), $outsideVariant, 99);
        $this->item($this->order('2026-10-01 12:00:00', 99999, 'pending'), $selectedVariant, 999);
        $this->item($this->order('2026-10-01 12:00:00', 99999, 'refunded'), $selectedVariant, 999);
        $params = ['period' => 'monthly', 'start_date' => '2026-09-30', 'end_date' => '2026-10-02'];
        $response = $this->actingAs($this->user('staff'))->get('/admin?'.http_build_query($params))->assertOk()
            ->assertViewHas('revenue', 40000)->assertViewHas('ordersCount', 2)
            ->assertViewHas('customers', 6)->assertViewHas('toPack', 1)
            ->assertSee('₱200.00')->assertSee('Current packing queue: 1 orders')
            ->assertSee('Selected dates: Sep 30, 2026 – Oct 2, 2026 (inclusive).')
            ->assertSee(route('admin.reports.export', $params))->assertSee('sales-line-chart')
            ->assertSee('View exact sales amounts');
        $best = $response->viewData('best');
        $this->assertCount(1, $best);
        $this->assertSame($selected->id, (int) $best[0]->product_id);
        $this->assertSame(8, (int) $best[0]->units);
        $this->assertSame(40000, $response->viewData('salesChart')['total']);
        $this->get('/admin?period=monthly')->assertOk()->assertViewHas('revenue', 229000)->assertViewHas('ordersCount', 4)
            ->assertSee('All-time units sold');
        $this->travelBack();
    }

    public static function invalidDateRanges(): array
    {
        return [
            'start without end' => [['start_date' => '2026-10-01']],
            'end without start' => [['end_date' => '2026-10-01']],
            'reversed' => [['start_date' => '2026-10-02', 'end_date' => '2026-10-01']],
            'future end' => [['start_date' => '2026-10-01', 'end_date' => '2026-10-13']],
            'invalid format' => [['start_date' => '10/01/2026', 'end_date' => '2026-10-02']],
            'impossible date' => [['start_date' => '2026-02-30', 'end_date' => '2026-03-01']],
            'daily buckets exceeded' => [['period' => 'daily', 'start_date' => '2026-06-14', 'end_date' => '2026-10-12']],
            'weekly days exceeded' => [['period' => 'weekly', 'start_date' => '2025-10-11', 'end_date' => '2026-10-12']],
            'monthly years exceeded' => [['period' => 'monthly', 'start_date' => '2015-10-12', 'end_date' => '2026-10-12']],
            'monthly buckets exceeded' => [['period' => 'monthly', 'start_date' => '2016-10-12', 'end_date' => '2026-10-12']],
            'yearly years exceeded' => [['period' => 'yearly', 'start_date' => '2015-10-12', 'end_date' => '2026-10-12']],
        ];
    }

    #[DataProvider('invalidDateRanges')]
    public function test_invalid_or_excessive_date_ranges_are_rejected(array $query): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Manila'));
        $this->actingAs($this->user('admin'))->getJson('/admin?'.http_build_query($query))->assertUnprocessable();
        $this->travelBack();
    }

    public function test_supported_maximum_ranges_and_empty_single_day_chart_are_safe(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Manila'));
        $report = app(SalesReport::class);
        $this->assertCount(120, $report->chart('daily', '2026-06-15', '2026-10-12')['rows']);
        $this->assertCount(54, $report->chart('weekly', '2025-10-12', '2026-10-12')['rows']);
        $this->assertCount(120, $report->chart('monthly', '2016-11-01', '2026-10-12')['rows']);
        $this->assertCount(11, $report->chart('yearly', '2016-10-12', '2026-10-12')['rows']);
        $this->actingAs($this->user('admin'))->get('/admin?start_date=2026-10-01&end_date=2026-10-01')->assertOk()
            ->assertViewHas('revenue', 0)->assertViewHas('ordersCount', 0)
            ->assertSee('No verified paid orders in this period yet.')->assertSee('No sales in selected dates')
            ->assertDontSee('NaN')->assertDontSee('INF');
        $single = $report->chart('daily', '2026-10-01', '2026-10-01');
        $this->assertSame([0], array_column($single['rows'], 'amount'));
        $this->assertCount(1, $single['plot']['points']);
        $this->travelBack();
    }

    public function test_today_range_excludes_future_payment_timestamps_consistently(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Manila'));
        $this->order('2026-10-12 08:00:00', 10000);
        $this->order('2026-10-12 12:01:00', 99999);
        $response = $this->actingAs($this->user('admin'))->get('/admin?start_date=2026-10-12&end_date=2026-10-12')->assertOk()
            ->assertViewHas('revenue', 10000)->assertViewHas('ordersCount', 1)->assertSee('The current day is still in progress.');
        $this->assertSame(10000, $response->viewData('salesChart')['total']);
        $this->assertTrue($response->viewData('salesChart')['rows'][0]['current']);
        $this->travelBack();
    }

    public function test_all_time_sales_cards_bestsellers_and_export_exclude_unconfirmed_payment_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Manila'));
        $verified = $this->order('2026-10-12 11:59:59', 10000);
        $future = $this->order('2026-10-12 12:00:01', 90000);
        $missing = $this->order('2026-10-11 10:00:00', 80000);
        $missing->update(['paid_at' => null]);
        $this->item($verified, $this->variant($this->product('Confirmed tee', '2026-01-01')), 2);
        foreach ([$future, $missing] as $order) {
            $this->item($order, $this->variant($this->product('Unconfirmed '.$order->id, '2026-01-01')), 99);
        }

        $this->actingAs($this->user('staff'))->get('/admin')->assertOk()
            ->assertViewHas('revenue', 10000)->assertViewHas('ordersCount', 1)
            ->assertViewHas('best', fn ($rows) => $rows->pluck('name')->all() === ['Confirmed tee'])
            ->assertViewHas('salesChart', fn ($chart) => $chart['total'] === 10000);
        $export = $this->get(route('admin.reports.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString($verified->number, $export);
        $this->assertStringNotContainsString($future->number, $export);
        $this->assertStringNotContainsString($missing->number, $export);
        $this->assertEquals([$verified->id], app(SalesReport::class)->paidOrders()->pluck('id')->all());
        $this->travelBack();
    }
}
