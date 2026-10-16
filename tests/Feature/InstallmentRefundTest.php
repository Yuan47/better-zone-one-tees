<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstallmentRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_refunds', 'shop.paymongo.webhook_secret' => 'test-webhook']);
        Http::preventStrayRequests();
    }

    private function user(string $role = 'customer'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function invoice(bool $walkIn = false): Order
    {
        $product = Product::create(['name' => 'Invoice refund tee', 'slug' => 'invoice-refund-'.Str::uuid(), 'category' => '', 'description' => 'Refund test clothing']);
        $variant = $product->variants()->create(['sku' => 'REFUND-'.Str::uuid(), 'size' => 'M', 'color' => 'White', 'stock' => 8,
            'retail_price' => 120000, 'wholesale_price' => 110000, 'reseller_price' => 100000]);
        $order = Order::create(['number' => 'ZOT-'.Str::uuid(), 'user_id' => $walkIn ? null : $this->user()->id,
            'tier' => 'retail', 'quantity' => 2, 'subtotal' => 240000, 'merchandise_base' => 240000, 'discount' => 0, 'shipping' => 10000, 'total' => 250000,
            'recipient' => 'Invoice buyer', 'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup',
            'payment_status' => 'paid', 'status' => 'completed', 'payment_plan' => 'partial', 'amount_paid' => 250000,
            'sales_channel' => $walkIn ? 'walk_in' : 'online', 'paid_at' => now()->subHours(2),
            'customer_confirmed_at' => $walkIn ? null : now()->subHour(), 'walk_in_received_at' => $walkIn ? now()->subHour() : null,
            'inventory_committed' => true, 'created_at' => now()->subDays(5)]);
        $order->items()->create(['variant_id' => $variant->id, 'name' => $product->name, 'size' => 'M', 'color' => 'White', 'quantity' => 2, 'unit_price' => 120000, 'total' => 240000]);

        return $order->fresh();
    }

    private function receipt(Order $order, int $amount, string $method = 'paymongo', array $attributes = []): PaymentAttempt
    {
        $receipt = $order->payments()->create($attributes + ['reference' => (string) Str::uuid(), 'method' => $method, 'status' => 'paid', 'amount' => $amount,
            'payment_id' => $method === 'paymongo' ? 'pay_'.Str::random(16) : null, 'received_at' => now()->subDays(2)]);
        if ($receipt->status === 'paid') {
            $order->update(['payment_id' => $receipt->payment_id ?? 'cash_'.$receipt->reference]);
        }

        return $receipt->fresh();
    }

    private function refund(Order $order, ?PaymentAttempt $source, int $amount, array $attributes = []): Refund
    {
        return $order->refunds()->create($attributes + ['requested_by' => $order->user_id, 'requested_amount' => $order->total,
            'reason' => 'The clothing has been returned for the approved refund.', 'status' => 'approved', 'approved_amount' => $amount,
            'payment_attempt_id' => $source?->id, 'reviewed_by' => $this->user('admin')->id, 'reviewed_at' => now()->subMinutes(5),
            'decision_note' => 'Approved against the selected invoice receipt.'])->fresh();
    }

    private function fakeProvider(string $reference, PaymentAttempt $source, int $amount, array $attributes = []): void
    {
        Http::fake(['https://api.paymongo.com/refunds/'.$reference => Http::response(['data' => ['id' => $reference, 'attributes' => $attributes + [
            'payment_id' => $source->payment_id, 'currency' => 'PHP', 'livemode' => false, 'amount' => $amount, 'status' => 'succeeded', 'updated_at' => time() - 60,
        ]]])]);
    }

    private function verify(Refund $refund, string $reference, ?PaymentAttempt $source = null)
    {
        return $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => $reference]
            + ($source ? ['payment_attempt_id' => $source->id] : []));
    }

    private function cash(Refund $refund, PaymentAttempt $source, array $attributes = [])
    {
        return $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/cash', $attributes + [
            'payment_attempt_id' => $source->id, 'cash_reason' => 'The approved cash amount was handed back to the buyer.',
        ]);
    }

    private function commerce(Order $order): array
    {
        return ['order' => $order->fresh()->getRawOriginal(), 'receipts' => $order->payments()->orderBy('id')->get()->map->getRawOriginal()->all(),
            'stock' => Variant::orderBy('id')->get(['id', 'stock', 'reserved'])->toArray()];
    }

    public function test_deposit_and_balance_are_refunded_against_their_own_payment_ids_without_mutating_invoice_receipts_or_stock(): void
    {
        $order = $this->invoice();
        $deposit = $this->receipt($order, 100000);
        $balance = $this->receipt($order, 150000);
        $before = $this->commerce($order);
        foreach ([[$deposit, 'ref_deposit'], [$balance, 'ref_balance']] as [$source, $reference]) {
            $refund = $this->refund($order, $source, $source->amount);
            $this->fakeProvider($reference, $source, $source->amount);
            $this->verify($refund, $reference, $source)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'payment_attempt_id' => $source->id, 'amount' => $source->amount, 'status' => 'succeeded']);
            $this->assertSame(0, Refund::remainingForPayment($order->fresh(), $source));
            Http::assertSentCount(1);
            Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api.paymongo.com/refunds/'.$reference);
        }
        $this->assertSame($balance->payment_id, $order->fresh()->payment_id);
        $this->assertSame(250000, (int) Refund::sum('amount'));
        $this->assertSame(0, Refund::remainingForOrder($order->fresh()));
        $this->assertSame($before, $this->commerce($order));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_selected_deposit_cannot_be_verified_using_the_last_balance_payment(): void
    {
        $order = $this->invoice();
        $deposit = $this->receipt($order, 100000);
        $balance = $this->receipt($order, 150000);
        $refund = $this->refund($order, $deposit, 40000);
        $this->fakeProvider('ref_wrong_payment', $balance, 40000);
        $this->verify($refund, 'ref_wrong_payment', $deposit)->assertSessionHasErrors('refund');
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'payment_attempt_id' => $deposit->id, 'status' => 'approved', 'amount' => null, 'provider_refund_id' => null]);
        Http::assertSentCount(1);
    }

    public function test_new_multi_receipt_refund_does_not_guess_the_last_payment_without_a_source(): void
    {
        $order = $this->invoice();
        $this->receipt($order, 100000);
        $this->receipt($order, 150000);
        $refund = $this->refund($order, null, 40000);
        $this->verify($refund, 'ref_choose_source')->assertSessionHasErrors('refund');
        Http::assertNothingSent();
        $this->assertNull($refund->fresh()->payment_attempt_id);
    }

    public function test_owner_review_requires_a_valid_source_with_enough_remaining_funds_and_binds_it(): void
    {
        $order = $this->invoice();
        $deposit = $this->receipt($order, 100000);
        $this->receipt($order, 150000);
        $foreign = $this->receipt($this->invoice(), 250000);
        $refund = $this->refund($order, null, 0, ['status' => 'requested', 'approved_amount' => null]);
        $this->actingAs($this->user('admin'));
        $url = '/admin/refunds/'.$refund->id;
        $data = ['decision' => 'approved', 'decision_note' => 'Returned clothing checked by owner.', 'approved_amount' => '1000.00'];
        $this->patch($url, $data)->assertSessionHasErrors('refund');
        $this->patch($url, ['payment_attempt_id' => $foreign->id] + $data)->assertSessionHasErrors('refund');
        $this->patch($url, ['payment_attempt_id' => $deposit->id, 'approved_amount' => '1000.01'] + $data)->assertSessionHasErrors('refund');
        $this->assertSame('requested', $refund->fresh()->status);
        $this->patch($url, ['payment_attempt_id' => $deposit->id] + $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'payment_attempt_id' => $deposit->id, 'approved_amount' => 100000, 'status' => 'approved']);
        Http::assertNothingSent();
    }

    public static function reservedRefundStatuses(): array
    {
        return [['succeeded'], ['pending'], ['processing']];
    }

    #[DataProvider('reservedRefundStatuses')]
    public function test_existing_confirmed_or_processing_provider_refunds_limit_the_selected_receipt(string $status): void
    {
        $order = $this->invoice();
        $deposit = $this->receipt($order, 100000);
        $this->receipt($order, 150000);
        $this->refund($order, $deposit, 70000, ['status' => $status, 'amount' => 70000, 'provider_refund_id' => 'ref_prior']);
        $refund = $this->refund($order, $deposit, 40000);
        $this->fakeProvider('ref_over_source', $deposit, 40000);
        $this->verify($refund, 'ref_over_source', $deposit)->assertSessionHasErrors('refund');
        $this->assertSame(30000, Refund::remainingForPayment($order->fresh(), $deposit));
        $this->assertSame(180000, Refund::remainingForOrder($order->fresh()));
        $this->assertNull($refund->fresh()->amount);
        Http::assertSentCount(1);
    }

    public function test_whole_invoice_cap_includes_legacy_refunds_when_a_different_receipt_still_has_funds(): void
    {
        $order = $this->invoice();
        $deposit = $this->receipt($order, 100000);
        $this->receipt($order, 150000);
        $this->refund($order, null, 200000, ['status' => 'succeeded', 'amount' => 200000, 'provider_refund_id' => 'ref_legacy']);
        $refund = $this->refund($order, $deposit, 70000);
        $this->fakeProvider('ref_over_invoice', $deposit, 70000);
        $this->verify($refund, 'ref_over_invoice', $deposit)->assertSessionHasErrors('refund');
        $this->assertSame(100000, Refund::remainingForPayment($order->fresh(), $deposit));
        $this->assertSame(50000, Refund::remainingForOrder($order->fresh()));
        $this->assertNull($refund->fresh()->amount);
        Http::assertSentCount(1);
    }

    public function test_an_approved_receipt_binding_cannot_be_changed_during_provider_verification(): void
    {
        $order = $this->invoice();
        $deposit = $this->receipt($order, 100000);
        $balance = $this->receipt($order, 150000);
        $refund = $this->refund($order, $deposit, 40000);
        $this->verify($refund, 'ref_changed_source', $balance)->assertSessionHasErrors('refund');
        Http::assertNothingSent();
        $this->assertSame($deposit->id, $refund->fresh()->payment_attempt_id);
    }

    public static function invalidProviderSources(): array
    {
        return [['foreign'], ['cash'], ['pending'], ['unreceived']];
    }

    #[DataProvider('invalidProviderSources')]
    public function test_provider_verification_rejects_foreign_cash_pending_or_unreceived_sources_before_http(string $case): void
    {
        $order = $this->invoice();
        $this->receipt($order, 250000);
        $source = match ($case) {
            'foreign' => $this->receipt($this->invoice(), 250000),
            'cash' => $this->receipt($order, 100000, 'cash'),
            'pending' => $this->receipt($order, 100000, 'paymongo', ['status' => 'pending', 'received_at' => null]),
            'unreceived' => $this->receipt($order, 100000, 'paymongo', ['received_at' => null]),
        };
        $refund = $this->refund($order, null, 40000);
        $this->verify($refund, 'ref_invalid_source', $source)->assertSessionHasErrors('refund');
        Http::assertNothingSent();
        $this->assertNull($refund->fresh()->provider_refund_id);
    }

    public function test_attached_historical_refund_can_refresh_its_original_payment_before_delivery_without_rebinding(): void
    {
        $order = $this->invoice();
        $source = $this->receipt($order, 250000);
        $order->update(['status' => 'packing', 'customer_confirmed_at' => null]);
        $refund = $this->refund($order, null, 40000, ['provider_refund_id' => 'ref_historical', 'status' => 'pending', 'amount' => 40000]);
        $this->fakeProvider('ref_historical', $source, 40000);
        $this->actingAs($this->user('staff'))->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasNoErrors();
        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->payment_attempt_id);
        Http::assertSentCount(1);
    }

    public function test_refund_timestamp_is_checked_against_the_selected_deposit_not_the_final_settlement_time(): void
    {
        $order = $this->invoice();
        $deposit = $this->receipt($order, 100000);
        $this->receipt($order, 150000);
        $refund = $this->refund($order, $deposit, 40000, ['provider_refund_id' => 'ref_deposit_time', 'status' => 'pending', 'amount' => 40000]);
        $this->fakeProvider('ref_deposit_time', $deposit, 40000, ['updated_at' => now()->subDay()->timestamp]);
        $this->actingAs($this->user('staff'))->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasNoErrors();
        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertTrue($refund->fresh()->confirmed_at->lessThan($order->paid_at));
    }

    public function test_owner_records_guest_walk_in_cash_return_atomically_with_audit_and_unchanged_commerce(): void
    {
        $order = $this->invoice(true);
        $cash = $this->receipt($order, 100000, 'cash');
        $this->receipt($order, 150000);
        $refund = $this->refund($order, $cash, 40000);
        $before = $this->commerce($order);
        $owner = $this->user('admin');
        $reason = 'The approved cash amount was handed back to the buyer.';
        $this->actingAs($owner)->post('/admin/refunds/'.$refund->id.'/cash', ['payment_attempt_id' => $cash->id, 'cash_reason' => $reason])->assertSessionHasNoErrors();
        $saved = $refund->fresh();
        $this->assertSame('succeeded', $saved->status);
        $this->assertSame(40000, $saved->amount);
        $this->assertSame($cash->id, $saved->payment_attempt_id);
        $this->assertSame($owner->id, $saved->cash_recorded_by);
        $this->assertSame($reason, $saved->cash_reason);
        $this->assertNotNull($saved->confirmed_at);
        $this->assertNull($saved->provider_refund_id);
        $this->assertNull($saved->requested_by);
        $this->assertNull($order->customer_confirmed_at);
        $this->assertSame(0, $order->review()->count());
        $audit = ActivityLog::where('action', 'refund.cash_recorded')->sole();
        $this->assertSame($owner->id, $audit->actor_id);
        $this->assertSame('admin', $audit->actor_role);
        $this->assertSame('refund', $audit->target_type);
        $this->assertSame($refund->id, $audit->target_id);
        $this->assertSame(['amount' => 40000, 'method' => 'cash', 'reason' => $reason], $audit->metadata);
        $this->assertSame($before, $this->commerce($order));
        $this->get('/admin/refunds/'.$refund->id)->assertOk()->assertSee('Recorded cash refund')->assertSee('Recorded cash amount')->assertDontSee('Check refund with PayMongo');
        $this->get('/admin/refunds')->assertOk()->assertSee('Invoice buyer');
        Http::assertNothingSent();
    }

    public function test_cash_recording_is_owner_only(): void
    {
        $order = $this->invoice(true);
        $cash = $this->receipt($order, 250000, 'cash');
        $refund = $this->refund($order, $cash, 40000);
        $url = '/admin/refunds/'.$refund->id.'/cash';
        $data = ['payment_attempt_id' => $cash->id, 'cash_reason' => 'Approved cash returned to the invoice buyer.'];
        $this->post($url, $data)->assertRedirect('/login');
        foreach (['customer', 'staff'] as $role) {
            $this->actingAs($this->user($role))->post($url, $data)->assertForbidden();
        }
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public static function invalidCashSources(): array
    {
        return [['foreign'], ['paymongo'], ['pending'], ['unreceived']];
    }

    #[DataProvider('invalidCashSources')]
    public function test_cash_recording_requires_a_received_paid_cash_receipt_from_the_same_invoice(string $case): void
    {
        $order = $this->invoice(true);
        $this->receipt($order, 250000);
        $source = match ($case) {
            'foreign' => $this->receipt($this->invoice(true), 250000, 'cash'),
            'paymongo' => $this->receipt($order, 100000),
            'pending' => $this->receipt($order, 100000, 'cash', ['status' => 'pending', 'received_at' => null]),
            'unreceived' => $this->receipt($order, 100000, 'cash', ['received_at' => null]),
        };
        $refund = $this->refund($order, null, 40000);
        $this->cash($refund, $source)->assertSessionHasErrors('refund');
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->cash_recorded_by);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public static function invalidCashStates(): array
    {
        return [['requested'], ['provider_reference'], ['unpaid'], ['not_delivered'], ['walk_in_not_received'], ['online_not_confirmed']];
    }

    #[DataProvider('invalidCashStates')]
    public function test_cash_return_requires_approval_full_payment_and_confirmed_handover(string $case): void
    {
        $order = $this->invoice($case !== 'online_not_confirmed');
        $cash = $this->receipt($order, 250000, 'cash');
        $refund = $this->refund($order, $cash, 40000);
        match ($case) {
            'requested' => $refund->update(['status' => 'requested']),
            'provider_reference' => $refund->update(['provider_refund_id' => 'ref_existing']),
            'unpaid' => $order->update(['payment_status' => 'partial', 'amount_paid' => 100000]),
            'not_delivered' => $order->update(['status' => 'packing']),
            'walk_in_not_received' => $order->update(['walk_in_received_at' => null]),
            'online_not_confirmed' => $order->update(['customer_confirmed_at' => null]),
        };
        $before = $refund->fresh()->getRawOriginal();
        $this->cash($refund, $cash)->assertSessionHasErrors('refund');
        $this->assertSame($before, $refund->fresh()->getRawOriginal());
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_cash_reason_is_required_and_cannot_be_replaced_by_injected_amount_or_provider_status(): void
    {
        $order = $this->invoice(true);
        $cash = $this->receipt($order, 250000, 'cash');
        $refund = $this->refund($order, $cash, 40000);
        foreach (['', 'short'] as $reason) {
            $this->cash($refund, $cash, ['cash_reason' => $reason])->assertSessionHasErrors('cash_reason');
        }
        $this->cash($refund, $cash, ['amount' => 200000, 'status' => 'pending', 'provider_refund_id' => 'ref_injected'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'amount' => 40000, 'status' => 'succeeded', 'provider_refund_id' => null]);
        $this->assertSame(1, ActivityLog::where('action', 'refund.cash_recorded')->count());
        Http::assertNothingSent();
    }

    public function test_cash_return_respects_both_source_and_whole_invoice_caps_including_in_flight_refunds(): void
    {
        $order = $this->invoice(true);
        $cash = $this->receipt($order, 100000, 'cash');
        $this->receipt($order, 150000);
        $this->refund($order, $cash, 70000, ['status' => 'succeeded', 'amount' => 70000]);
        $refund = $this->refund($order, $cash, 40000);
        $this->cash($refund, $cash)->assertSessionHasErrors('refund');
        $this->assertNull($refund->fresh()->amount);

        $other = $this->invoice(true);
        $otherCash = $this->receipt($other, 100000, 'cash');
        $otherProvider = $this->receipt($other, 150000);
        $this->refund($other, $otherProvider, 200000, ['status' => 'processing', 'amount' => 200000, 'provider_refund_id' => 'ref_reserved_invoice']);
        $otherRefund = $this->refund($other, $otherCash, 70000);
        $this->cash($otherRefund, $otherCash)->assertSessionHasErrors('refund');
        $this->assertSame(50000, Refund::remainingForOrder($other->fresh()));
        $this->assertNull($otherRefund->fresh()->amount);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_cash_source_cannot_change_after_approval_and_success_cannot_be_recorded_twice(): void
    {
        $order = $this->invoice(true);
        $deposit = $this->receipt($order, 100000, 'cash');
        $balance = $this->receipt($order, 150000, 'cash');
        $refund = $this->refund($order, $deposit, 40000);
        $this->cash($refund, $balance)->assertSessionHasErrors('refund');
        $this->cash($refund, $deposit)->assertSessionHasNoErrors();
        $before = $refund->fresh()->getRawOriginal();
        $this->cash($refund, $deposit)->assertSessionHasErrors('refund');
        $this->assertSame($before, $refund->fresh()->getRawOriginal());
        $this->assertSame(1, ActivityLog::where('action', 'refund.cash_recorded')->count());
        Http::assertNothingSent();
    }

    public static function auditFailures(): array
    {
        return [['exception'], ['missing']];
    }

    #[DataProvider('auditFailures')]
    public function test_cash_audit_failure_rolls_back_the_refund_confirmation(string $failure): void
    {
        $order = $this->invoice(true);
        $cash = $this->receipt($order, 250000, 'cash');
        $refund = $this->refund($order, $cash, 40000);
        $before = $refund->getRawOriginal();
        $this->mock(ActivityRecorder::class, function (MockInterface $mock) use ($failure) {
            $expectation = $mock->shouldReceive('record')->once();
            if ($failure === 'exception') {
                $expectation->andThrow(new \RuntimeException('Audit storage unavailable'));
            } else {
                $expectation->andReturnNull();
            }
        });
        $this->cash($refund, $cash)->assertSessionHasErrors('refund');
        $this->assertSame($before, $refund->fresh()->getRawOriginal());
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_invoice_received_total_counts_paid_receipts_and_never_a_pending_balance_or_overstated_cache(): void
    {
        $order = $this->invoice();
        $deposit = $this->receipt($order, 100000, 'cash');
        $this->receipt($order, 150000, 'paymongo', ['status' => 'pending', 'received_at' => null]);
        $this->assertSame(100000, Refund::receivedForOrder($order->fresh()));
        $this->assertSame(100000, Refund::remainingForOrder($order->fresh()));
        $refund = $this->refund($order, $deposit, 100001);
        $this->cash($refund, $deposit)->assertSessionHasErrors('refund');
        $this->assertNull($refund->fresh()->amount);
        Http::assertNothingSent();
    }
}
