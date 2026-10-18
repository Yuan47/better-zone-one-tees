<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Models\Variant;
use App\Services\Forecast;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForecastReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00', 'Asia/Manila'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function product(string $name, string $created = '2026-04-01 00:00:00'): Variant
    {
        $product = Product::create(['name' => $name, 'slug' => 'reliability-'.uniqid(), 'category' => '',
            'description' => 'Forecast reliability fixture', 'created_at' => $created]);

        return $product->variants()->create(['sku' => 'RELIABLE-'.uniqid(), 'size' => 'M', 'color' => 'White',
            'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000, 'stock' => 100]);
    }

    private function sale(Variant $variant, string $date, int $quantity, string $status = 'paid'): Order
    {
        $order = Order::create(['number' => 'FORECAST-'.uniqid(), 'user_id' => User::factory()->create()->id,
            'tier' => 'retail', 'quantity' => $quantity, 'subtotal' => $quantity * 10000,
            'merchandise_base' => $quantity * 10000, 'discount' => 0, 'shipping' => 15000,
            'total' => $quantity * 10000 + 15000, 'paid_at' => $date, 'payment_status' => $status,
            'status' => 'completed', 'recipient' => 'Forecast buyer', 'phone' => '09123456789',
            'address' => 'Store pickup', 'delivery_method' => 'pickup']);
        $order->items()->create(['variant_id' => $variant->id, 'name' => $variant->product->name,
            'size' => 'M', 'color' => 'White', 'quantity' => $quantity, 'unit_price' => 10000,
            'total' => $quantity * 10000]);

        return $order;
    }

    public function test_regression_error_is_measured_against_time_ordered_held_out_months(): void
    {
        $result = app(Forecast::class)->backtest([100, 120, 110, 150, 140, 180],
            ['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'])['regression'];

        $this->assertSame('available', $result['status']);
        $this->assertSame(3, $result['tested_months']);
        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($result['points'], 'month'));
        $this->assertSame([3, 4, 5], array_column($result['points'], 'training_months'));
        $this->assertEquals([120, 155, 157], array_column($result['points'], 'prediction'));
        $this->assertEquals([150, 140, 180], array_column($result['points'], 'actual'));
        $this->assertEqualsWithDelta(68 / 3, $result['mae'], 0.000001);
        $this->assertEqualsWithDelta(68 / 470 * 100, $result['wape'], 0.000001);
    }

    public function test_growth_error_uses_the_required_formula_and_does_not_claim_an_accuracy_score(): void
    {
        $result = app(Forecast::class)->backtest([100, 120, 140, 160])['growth'];

        $this->assertSame(2, $result['tested_months']);
        $this->assertEqualsWithDelta(144, $result['points'][0]['prediction'], 0.000001);
        $this->assertEqualsWithDelta(163.33333333, $result['points'][1]['prediction'], 0.000001);
        $this->assertEqualsWithDelta(3.66666667, $result['mae'], 0.000001);
        $this->assertEqualsWithDelta(2.44444444, $result['wape'], 0.000001);
        $this->assertArrayNotHasKey('accuracy', $result);
    }

    public function test_changing_a_held_out_or_later_sale_never_changes_its_earlier_prediction(): void
    {
        $forecast = app(Forecast::class);
        $before = $forecast->backtest([100, 200, 300, 400, 500]);
        $after = $forecast->backtest([100, 200, 300, 10000, 90000]);

        foreach (['growth', 'regression'] as $method) {
            $this->assertEquals($before[$method]['points'][0]['prediction'], $after[$method]['points'][0]['prediction']);
            $this->assertNotEquals($before[$method]['mae'], $after[$method]['mae']);
        }
        $this->assertEquals(400, $after['regression']['points'][0]['prediction']);
        $this->assertSame(10000, $after['regression']['points'][0]['actual']);
    }

    public function test_a_forecast_can_be_available_before_there_is_enough_history_to_measure_error(): void
    {
        $forecast = app(Forecast::class);
        $this->assertSame('insufficient_history', $forecast->linear([100, 200])['status']);
        $this->assertSame('available', $forecast->linear([100, 200, 300])['status']);
        $this->assertSame('insufficient_history', $forecast->growth([100])['status']);
        $this->assertSame('zero_baseline', $forecast->growth([0, 100])['status']);
        $results = $forecast->backtest([100, 200, 300]);
        $this->assertSame(1, $results['growth']['tested_months']);
        $this->assertSame(0, $results['regression']['tested_months']);
        foreach ($results as $result) {
            $this->assertSame('insufficient_history', $result['status']);
            $this->assertNull($result['mae']);
            $this->assertNull($result['wape']);
        }
    }

    public function test_zero_actual_sales_never_produce_a_fabricated_percentage_accuracy(): void
    {
        $results = app(Forecast::class)->backtest([0, 0, 0, 0, 0]);
        $this->assertSame('available', $results['regression']['status']);
        $this->assertSame(2, $results['regression']['tested_months']);
        $this->assertEquals(0, $results['regression']['mae']);
        $this->assertNull($results['regression']['wape']);
        $this->assertSame('insufficient_history', $results['growth']['status']);
        $this->assertSame(0, $results['growth']['tested_months']);
        $this->assertNull($results['growth']['mae']);
    }

    public function test_growth_skips_zero_baseline_tests_and_resumes_when_a_rate_can_be_calculated(): void
    {
        $result = app(Forecast::class)->backtest([0, 10, 20, 30, 40], ['A', 'B', 'C', 'D', 'E'])['growth'];
        $this->assertSame(2, $result['tested_months']);
        $this->assertSame(['D', 'E'], array_column($result['points'], 'month'));
        $this->assertEquals([40, 45], array_column($result['points'], 'prediction'));
        $this->assertEquals(7.5, $result['mae']);
    }

    public function test_negative_estimates_and_whole_piece_rounding_match_the_forecast_used_for_planning(): void
    {
        $forecast = app(Forecast::class);
        $declining = $forecast->backtest([30, 20, 10, 5, 0])['regression'];
        $this->assertEquals([0, 0], array_column($declining['points'], 'prediction'));
        $this->assertEquals(2.5, $declining['mae']);
        $this->assertEquals(100, $declining['wape']);
        $units = $forecast->backtest([1, 2, 2, 3, 3], [], true)['regression'];
        $this->assertEquals([3, 4], array_column($units['points'], 'prediction'));
        $this->assertEquals(0.5, $units['mae']);
        $this->assertEqualsWithDelta(100 / 6, $units['wape'], 0.000001);
    }

    public function test_only_eligible_completed_months_enter_forecasts_and_backtests_and_refunds_remain_distinct(): void
    {
        $variant = $this->product('Established Tee');
        foreach ([4, 5, 6, 7, 8, 9] as $index => $month) {
            $order = $this->sale($variant, sprintf('2026-%02d-15 12:00:00', $month), $index + 1);
        }
        Refund::create(['order_id' => $order->id, 'requested_by' => $order->user_id, 'status' => 'succeeded',
            'requested_amount' => 20000, 'approved_amount' => 20000, 'amount' => 20000,
            'reason' => 'Previously confirmed refund fixture', 'confirmed_at' => '2026-10-01 12:00:00']);
        foreach ([['2026-03-15 12:00:00', 'paid'], ['2026-10-01 12:00:00', 'paid'],
            ['2026-11-01 12:00:00', 'paid'], ['2026-09-16 12:00:00', 'partially_paid'],
            ['2026-09-17 12:00:00', 'pending']] as [$date, $status]) {
            $this->sale($variant, $date, 999, $status);
        }

        $forecast = app(Forecast::class);
        $revenue = $forecast->revenue();
        $this->assertSame(['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'], $revenue['months']);
        $this->assertSame([10000, 20000, 30000, 40000, 50000, 60000], $revenue['values']);
        $this->assertSame('October 2026', $revenue['target']);
        $this->assertSame(3, $revenue['backtest']['regression']['tested_months']);
        $this->assertEquals(0, $revenue['backtest']['regression']['mae']);
        $row = $forecast->rows()[0];
        $this->assertSame([1, 2, 3, 4, 5, 6], $row['values']);
        $this->assertSame(21, $row['sold']);
        $this->assertSame(7, $row['forecast']);
        $this->assertSame('available', $row['backtest']['regression']['status']);
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get('/admin/forecast')->assertOk()
            ->assertSee('confirmed clothing sales before refunds')->assertSee('fully paid pieces before returns')
            ->assertSee('How well did past estimates perform?')->assertSee('Average miss: ₱0.00')
            ->assertSee('0.00% error')->assertDontSee('100% accurate');
        $this->assertSame(20000, Refund::firstOrFail()->amount);
        Http::assertNothingSent();
    }

    public function test_new_and_unsold_products_do_not_inherit_fabricated_demand_or_perfect_error_scores(): void
    {
        $new = $this->product('New Tee', '2026-09-01 00:00:00');
        $unsold = $this->product('Unsold Tee');
        $this->sale($new, '2026-09-15 12:00:00', 2);
        $this->sale($unsold, '2026-10-01 12:00:00', 99);
        $rows = collect(app(Forecast::class)->rows())->keyBy('name');
        $this->assertSame(['2026-09'], $rows['New Tee']['months']);
        $this->assertSame('insufficient_history', $rows['New Tee']['regression']['status']);
        $this->assertSame('no_observed_sales', $rows['Unsold Tee']['regression']['status']);
        foreach ($rows as $row) {
            $this->assertNull($row['forecast']);
            $this->assertNull($row['reorder']);
            foreach ($row['backtest'] as $result) {
                $this->assertSame(0, $result['tested_months']);
                $this->assertNull($result['mae']);
                $this->assertNull($result['wape']);
            }
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/forecast')->assertOk()
            ->assertSee('Accuracy not measured yet')->assertSee('Needs sales history')
            ->assertSee('zero demand is not assumed.')->assertDontSee('Average miss: ₱0.00');
        Http::assertNothingSent();
    }

    public function test_product_backtests_do_not_count_estimates_before_any_paid_sales_were_known(): void
    {
        $september = $this->product('First sold in September');
        $august = $this->product('First sold in August');
        $this->sale($september, '2026-09-15 12:00:00', 10);
        $this->sale($august, '2026-08-15 12:00:00', 10);
        $this->sale($august, '2026-09-15 12:00:00', 20);

        $rows = collect(app(Forecast::class)->rows())->keyBy('name');
        $this->assertSame([0, 0, 0, 0, 0, 10], $rows['First sold in September']['values']);
        $this->assertNotNull($rows['First sold in September']['forecast']);
        foreach ($rows['First sold in September']['backtest'] as $result) {
            $this->assertSame(0, $result['tested_months']);
            $this->assertSame([], $result['points']);
            $this->assertNull($result['mae']);
            $this->assertNull($result['wape']);
        }
        $regression = $rows['First sold in August']['backtest']['regression'];
        $this->assertSame(1, $regression['tested_months']);
        $this->assertSame('insufficient_history', $regression['status']);
        $this->assertSame(['2026-09'], array_column($regression['points'], 'month'));
        $this->assertSame(8.0, $regression['points'][0]['prediction']);
        $this->assertSame(20, $regression['points'][0]['actual']);
        $this->assertNull($regression['mae']);
        $this->assertNull($regression['wape']);
        Http::assertNothingSent();
    }

    public function test_observed_sales_before_the_six_month_window_allow_legitimate_zero_demand_backtests(): void
    {
        $variant = $this->product('Previously sold product', '2026-03-01 00:00:00');
        $this->sale($variant, '2026-03-15 12:00:00', 10);

        $row = app(Forecast::class)->rows()[0];
        $this->assertSame(['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'], $row['months']);
        $this->assertSame([0, 0, 0, 0, 0, 0], $row['values']);
        $this->assertTrue($row['observed_history']);
        $this->assertSame(0, $row['forecast']);
        $this->assertSame(3, $row['backtest']['regression']['tested_months']);
        $this->assertSame('available', $row['backtest']['regression']['status']);
        $this->assertEquals(0, $row['backtest']['regression']['mae']);
        $this->assertNull($row['backtest']['regression']['wape']);
        Http::assertNothingSent();
    }
}
