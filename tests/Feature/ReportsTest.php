<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Forecast;
use App\Services\SalesReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertArrayNotHasKey('category', $rows[0]);
        $this->travelBack();
    }
}
