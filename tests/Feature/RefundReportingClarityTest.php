<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\SalesReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundReportingClarityTest extends TestCase
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

    private function invoice(array $overrides = []): Order
    {
        return Order::create($overrides + ['number' => 'REFUND-REPORT-'.Str::random(12), 'user_id' => null,
            'recipient' => 'Reporting customer', 'phone' => '09123456789', 'address' => 'Store pickup',
            'delivery_method' => 'pickup', 'sales_channel' => 'walk_in', 'tier' => 'retail', 'quantity' => 2,
            'merchandise_base' => 11000, 'discount' => 1000, 'subtotal' => 10000, 'shipping' => 2000, 'total' => 12000,
            'amount_paid' => 12000, 'payment_status' => 'paid', 'paid_at' => '2026-09-10 10:00:00',
            'status' => 'completed', 'walk_in_received_at' => '2026-09-10 10:00:00', 'created_at' => '2026-09-09 10:00:00']);
    }

    private function receipt(Order $order, int $amount = 12000, array $overrides = []): PaymentAttempt
    {
        return $order->payments()->create($overrides + ['reference' => (string) Str::uuid(), 'method' => 'paymongo',
            'status' => 'paid', 'amount' => $amount, 'payment_id' => 'pay_report_'.Str::random(12),
            'received_at' => '2026-09-10 10:00:00']);
    }

    private function refund(Order $order, int $amount = 3000, array $overrides = []): Refund
    {
        return $order->refunds()->create($overrides + ['requested_by' => null, 'requested_amount' => $amount,
            'approved_amount' => $amount, 'amount' => $amount, 'status' => 'succeeded',
            'provider_refund_id' => 'ref_report_'.Str::random(12), 'reason' => 'Confirmed refund reporting fixture.',
            'confirmed_at' => '2026-10-15 10:00:00', 'created_at' => '2026-10-14 10:00:00']);
    }

    public function test_paid_period_is_restated_by_later_confirmed_refunds_without_changing_clothing_sales_or_original_invoice(): void
    {
        $order = $this->invoice(['payment_plan' => 'partial', 'due_at' => '2026-09-16 23:59:59', 'original_due_at' => '2026-09-16 23:59:59']);
        $this->receipt($order, 5000);
        $this->receipt($order, 7000);
        $this->refund($order);
        $this->refund($this->invoice(['paid_at' => '2026-10-01 10:00:00']), 1000);
        $before = $order->fresh()->getAttributes();
        $report = app(SalesReport::class);

        $this->assertSame(['original_invoice' => 12000, 'confirmed_refunds' => 3000, 'net_invoice' => 9000,
            'as_of' => '2026-10-18T12:00:00+08:00'], $report->invoiceSummary('2026-09-01', '2026-09-30'));
        $this->assertSame(10000, $report->chart('monthly', '2026-09-01', '2026-09-30')['total']);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get('/admin?start_date=2026-09-01&end_date=2026-09-30')
            ->assertOk()->assertViewHas('revenue', 10000)->assertViewHas('ordersCount', 1)
            ->assertViewHas('invoiceSummary', fn ($summary) => $summary['confirmed_refunds'] === 3000 && $summary['net_invoice'] === 9000)
            ->assertSee('Net invoice value')->assertSee('₱90.00')->assertSee('includes delivery')
            ->assertSee('A later refund updates the original paid invoice')->assertSee('no clothing/delivery allocation');
        // Operations is a separate event-date report: the September sale's
        // October refund is counted in October, not in September.
        $this->get('/admin/operations?start_date=2026-10-14&end_date=2026-10-16')->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['confirmed_refunds'] === 2 && $stats['refund_amount'] === 4000 && $stats['paid_orders'] === 0);
        Http::assertNothingSent();
    }

    public function test_only_confirmed_refunds_known_at_generation_time_reduce_net_invoice_and_full_refund_reaches_zero(): void
    {
        $partial = $this->invoice();
        $this->receipt($partial);
        $this->refund($partial, 1000);
        foreach (['requested', 'approved', 'pending', 'processing', 'failed', 'rejected'] as $status) {
            $this->refund($partial, 500, ['status' => $status]);
        }
        foreach ([['confirmed_at' => null], ['confirmed_at' => '2026-10-18 12:00:01'],
            ['provider_refund_id' => null], ['provider_refund_id' => ''], ['amount' => 0]] as $invalid) {
            $this->refund($partial, 500, $invalid);
        }
        $full = $this->invoice();
        $this->receipt($full);
        $this->refund($full, 12000);

        $summary = app(SalesReport::class)->invoiceSummary();
        $this->assertSame(24000, $summary['original_invoice']);
        $this->assertSame(13000, $summary['confirmed_refunds']);
        $this->assertSame(11000, $summary['net_invoice']);
        $asOfEarlier = Carbon::parse('2026-10-15 09:59:59', 'Asia/Manila');
        $this->travelTo($asOfEarlier);
        $this->assertSame(0, app(SalesReport::class)->invoiceSummary()['confirmed_refunds']);
        Http::assertNothingSent();
    }

    public function test_refund_totals_are_capped_per_invoice_by_received_receipts_not_by_cached_paid_amount_or_other_orders(): void
    {
        $underReceived = $this->invoice(['amount_paid' => 999999]);
        $this->receipt($underReceived, 5000);
        $this->receipt($underReceived, 7000, ['status' => 'pending', 'received_at' => null]);
        $this->refund($underReceived, 999999);
        $overReceived = $this->invoice();
        $this->receipt($overReceived, 20000);
        $this->refund($overReceived, 999999);
        $unrefunded = $this->invoice();
        $this->receipt($unrefunded);

        $summary = app(SalesReport::class)->invoiceSummary();
        $this->assertSame(36000, $summary['original_invoice']);
        $this->assertSame(17000, $summary['confirmed_refunds']);
        $this->assertSame(19000, $summary['net_invoice']);
        $this->assertSame(999999, $underReceived->fresh()->amount_paid);
        $this->assertSame(2, $underReceived->payments()->count());
        Http::assertNothingSent();
    }

    public function test_cash_refunds_need_received_cash_from_same_invoice_and_matching_owner_audit(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $order = $this->invoice();
        $cash = $this->receipt($order, 12000, ['method' => 'cash', 'payment_id' => null]);
        $attributes = ['provider_refund_id' => null, 'payment_attempt_id' => $cash->id,
            'cash_recorded_by' => $owner->id, 'cash_reason' => 'Cash actually returned to the customer.'];
        $valid = $this->refund($order, 3000, $attributes);
        app(ActivityRecorder::class)->record($owner, 'refund.cash_recorded', $valid, ['amount' => 3000, 'reason' => $attributes['cash_reason']]);
        $this->refund($order, 2000, $attributes); // No audit.
        $foreign = $this->receipt($this->invoice(), 12000, ['method' => 'cash', 'payment_id' => null]);
        foreach ([['payment_attempt_id' => $foreign->id], ['cash_reason' => null], ['cash_recorded_by' => $staff->id]] as $invalid) {
            $refund = $this->refund($order, 1000, $invalid + $attributes);
            $actor = isset($invalid['cash_recorded_by']) ? $staff : $owner;
            app(ActivityRecorder::class)->record($actor, 'refund.cash_recorded', $refund, ['amount' => 1000]);
        }

        $summary = app(SalesReport::class)->invoiceSummary();
        $this->assertSame(3000, $summary['confirmed_refunds']);
        $this->assertSame(21000, $summary['net_invoice']);
        Http::assertNothingSent();
    }

    public function test_legacy_paid_invoices_keep_fallback_but_partial_pending_missing_and_future_paid_dates_are_excluded(): void
    {
        $this->refund($this->invoice(['amount_paid' => 0]), 3000);
        $this->refund($this->invoice(['amount_paid' => 2000]), 3000);
        foreach ([['payment_status' => 'partially_paid', 'amount_paid' => 5000], ['payment_status' => 'pending'],
            ['paid_at' => null], ['paid_at' => '2026-10-18 12:00:01']] as $excluded) {
            $order = $this->invoice($excluded);
            $this->receipt($order);
            $this->refund($order);
        }
        $summary = app(SalesReport::class)->invoiceSummary();
        $this->assertSame(24000, $summary['original_invoice']);
        $this->assertSame(5000, $summary['confirmed_refunds']);
        $this->assertSame(19000, $summary['net_invoice']);
        $empty = app(SalesReport::class)->invoiceSummary('2026-08-01', '2026-08-31');
        $this->assertSame(0, $empty['original_invoice']);
        $this->assertSame(0, $empty['confirmed_refunds']);
        $this->assertSame(0, $empty['net_invoice']);
        Http::assertNothingSent();
    }

    public function test_csv_keeps_original_fields_and_reconciles_invoice_refunds_and_net_value_for_paid_date_cohort(): void
    {
        $partial = $this->invoice(['recipient' => '=unsafe formula']);
        $this->receipt($partial);
        $this->refund($partial, 3000);
        $full = $this->invoice();
        $this->receipt($full);
        $this->refund($full, 12000);
        $excluded = $this->invoice(['paid_at' => '2026-10-01 10:00:00']);
        $this->refund($excluded);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $csv = $this->get('/admin/reports/export?start_date=2026-09-01&end_date=2026-09-30')->assertOk()->streamedContent();
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), preg_split('/\r?\n/', trim($csv)));

        $this->assertCount(3, $rows);
        $this->assertSame('Clothing after discounts before refunds PHP', $rows[0][6]);
        $this->assertSame(['Confirmed refunds including delivery PHP', 'Net invoice including delivery PHP', 'Refunds known as of Asia/Manila'], array_slice($rows[0], 11));
        $this->assertSame(["'=unsafe formula", 'Walk-in'], array_slice($rows[1], 9, 2));
        $this->assertSame(['110.00', '10.00', '100.00', '20.00', '120.00'], array_slice($rows[1], 4, 5));
        $this->assertSame(['30.00', '90.00', '2026-10-18T12:00:00+08:00'], array_slice($rows[1], 11));
        $this->assertSame(['120.00', '0.00', '2026-10-18T12:00:00+08:00'], array_slice($rows[2], 11));
        $this->assertSame('2', $rows[1][2]);
        $this->assertStringNotContainsString($excluded->number, $csv);
        $totals = app(SalesReport::class)->invoiceSummary('2026-09-01', '2026-09-30');
        $this->assertSame($totals['original_invoice'], (int) round(((float) $rows[1][8] + (float) $rows[2][8]) * 100));
        $this->assertSame($totals['confirmed_refunds'], (int) round(((float) $rows[1][11] + (float) $rows[2][11]) * 100));
        $this->assertSame($totals['net_invoice'], (int) round(((float) $rows[1][12] + (float) $rows[2][12]) * 100));
        Http::assertNothingSent();
    }
}
