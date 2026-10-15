<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Models\Variant;
use App\Services\DeliveryRecovery;
use App\Services\OrderFlow;
use App\Services\SalesReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RefundTest extends TestCase
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
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function order(?User $customer = null): Order
    {
        $customer ??= $this->user();
        $product = Product::create(['name' => 'Refund Test Tee', 'slug' => 'refund-test-'.uniqid(), 'category' => '', 'description' => 'Refund test clothing']);
        $variant = $product->variants()->create(['sku' => 'REFUND-'.uniqid(), 'size' => 'M', 'color' => 'White', 'stock' => 20,
            'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
        $flow = app(OrderFlow::class);
        $order = $flow->reserve([$variant->id => 2], ['recipient' => 'Refund Buyer', 'phone' => '09123456789', 'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 5000], $customer->id);
        DB::transaction(function () use ($flow, $order) {
            $flow->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_'.uniqid());
        });
        $order->refresh()->update(['status' => 'completed', 'paid_at' => now()->subHours(2), 'created_at' => now()->subHours(3)]);

        return $order->fresh();
    }

    private function refund(Order $order, array $attributes = []): Refund
    {
        return $order->refunds()->create($attributes + ['requested_by' => $order->user_id, 'requested_amount' => $order->total,
            'status' => 'requested', 'reason' => 'The size does not fit as expected.', 'created_at' => now()->subMinutes(5)])->fresh();
    }

    private function approved(Order $order, int $amount = 10000, array $attributes = []): Refund
    {
        return $this->refund($order, $attributes + ['status' => 'approved', 'approved_amount' => $amount,
            'reviewed_by' => $this->user('admin')->id, 'reviewed_at' => now()->subMinutes(2), 'decision_note' => 'Approved for partial refund.']);
    }

    private function provider(Refund $refund, array $attributes = [], string $id = 'ref_test'): array
    {
        return ['data' => ['id' => $id, 'type' => 'refund', 'attributes' => $attributes + ['payment_id' => $refund->order->payment_id,
            'currency' => 'PHP', 'livemode' => false, 'amount' => 10000, 'status' => 'succeeded', 'updated_at' => time() - 60]]];
    }

    private function fakeRefund(array $data): void
    {
        Http::fake(['https://api.paymongo.com/refunds/*' => Http::response($data)]);
    }

    private function unchangedCommerce(Order $order): array
    {
        $fresh = $order->fresh();

        return ['order' => $fresh->only(['quantity', 'tier', 'subtotal', 'merchandise_base', 'discount', 'shipping', 'total', 'payment_status', 'payment_id', 'status', 'inventory_committed', 'inventory_released']) + ['paid_at' => $fresh->getRawOriginal('paid_at')],
            'stock' => Variant::orderBy('id')->get(['id', 'stock', 'reserved'])->toArray(), 'sales' => app(SalesReport::class)->chart()['total']];
    }

    public function test_customer_requests_own_verified_payment_without_setting_refund_amount_or_status(): void
    {
        $customer = $this->user();
        $order = $this->order($customer);
        $before = $this->unchangedCommerce($order);
        $this->actingAs($customer)->post('/orders/'.$order->id.'/refunds', ['reason' => 'The size does not fit as expected.', 'status' => 'succeeded', 'amount' => 999999, 'requested_amount' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $refund = Refund::sole();
        $this->assertSame('requested', $refund->status);
        $this->assertSame($order->total, $refund->requested_amount);
        $this->assertSame($customer->id, $refund->requested_by);
        $this->assertNull($refund->amount);
        $this->assertNull($refund->provider_refund_id);
        $this->assertSame($before, $this->unchangedCommerce($order));
        Http::assertNothingSent();
    }

    public function test_guest_other_customer_and_non_customer_roles_cannot_request_a_refund(): void
    {
        $order = $this->order();
        $payload = ['reason' => 'The size does not fit as expected.'];
        $this->post('/orders/'.$order->id.'/refunds', $payload)->assertRedirect('/login');
        $this->actingAs($this->user())->post('/orders/'.$order->id.'/refunds', $payload)->assertForbidden();
        foreach (['staff', 'admin'] as $role) {
            $user = $this->user($role);
            $owned = $this->order($user);
            $this->actingAs($user)->post('/orders/'.$owned->id.'/refunds', $payload)->assertForbidden();
        }
        $this->assertDatabaseCount('refunds', 0);
        Http::assertNothingSent();
    }

    public static function invalidPayments(): array
    {
        return [['pending', 'pay_test'], ['cancelled', 'pay_test'], ['paid', null]];
    }

    #[DataProvider('invalidPayments')]
    public function test_only_paid_orders_with_verified_payment_reference_allow_requests(string $status, ?string $payment): void
    {
        $order = $this->order();
        $order->update(['payment_status' => $status, 'payment_id' => $payment]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/refunds', ['reason' => 'The size does not fit as expected.'])->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 0);
        Http::assertNothingSent();
    }

    public static function activeRequests(): array
    {
        return [['requested'], ['approved'], ['pending'], ['processing']];
    }

    #[DataProvider('activeRequests')]
    public function test_duplicate_review_or_processing_request_is_blocked(string $status): void
    {
        $order = $this->order();
        $this->refund($order, ['status' => $status]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/refunds', ['reason' => 'Another request for the same payment.'])->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 1);
    }

    public static function retryStatuses(): array
    {
        return [['rejected'], ['failed'], ['succeeded']];
    }

    #[DataProvider('retryStatuses')]
    public function test_closed_requests_allow_retry_only_for_remaining_confirmed_amount(string $status): void
    {
        $order = $this->order();
        $this->refund($order, ['status' => $status, 'amount' => 10000]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/refunds', ['reason' => 'Requesting the remaining payment refund.'])->assertSessionHasNoErrors();
        $new = Refund::where('status', 'requested')->sole();
        $this->assertSame($status === 'succeeded' ? 15000 : 25000, $new->requested_amount);
        $this->assertDatabaseCount('refunds', 2);
    }

    public function test_a_fully_confirmed_refund_cannot_open_another_request(): void
    {
        $order = $this->order();
        $this->refund($order, ['status' => 'succeeded', 'amount' => $order->total]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/refunds', ['reason' => 'Requesting another payment refund.'])->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_owner_can_approve_partial_php_amount_and_reject_with_customer_visible_note(): void
    {
        $order = $this->order();
        $refund = $this->refund($order);
        $owner = $this->user('admin');
        $this->actingAs($owner)->patch('/admin/refunds/'.$refund->id, ['decision' => 'approved', 'approved_amount' => '12.34', 'decision_note' => 'Approved for the size adjustment.'])->assertSessionHasNoErrors();
        $refund->refresh();
        $this->assertSame('approved', $refund->status);
        $this->assertSame(1234, $refund->approved_amount);
        $this->assertSame($owner->id, $refund->reviewed_by);
        $this->assertNotNull($refund->reviewed_at);
        $other = $this->order();
        $rejected = $this->refund($other);
        $this->patch('/admin/refunds/'.$rejected->id, ['decision' => 'rejected', 'decision_note' => 'The supplied issue needs additional evidence.'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertNull($rejected->fresh()->approved_amount);
        $this->actingAs($other->user)->get('/orders/'.$other->id)->assertOk()->assertSee('The supplied issue needs additional evidence.');
        Http::assertNothingSent();
    }

    public function test_approval_cannot_exceed_request_remaining_payment_or_fractional_cent_precision(): void
    {
        $owner = $this->user('admin');
        $order = $this->order();
        $refund = $this->refund($order, ['requested_amount' => 3000]);
        $this->actingAs($owner)->patch('/admin/refunds/'.$refund->id, ['decision' => 'approved', 'approved_amount' => '30.01', 'decision_note' => 'Reviewing partial refund.'])->assertSessionHasErrors('refund');
        $this->patch('/admin/refunds/'.$refund->id, ['decision' => 'approved', 'approved_amount' => '12.345', 'decision_note' => 'Reviewing partial refund.'])->assertSessionHasErrors('approved_amount');
        $this->refund($order, ['status' => 'succeeded', 'amount' => 23000]);
        $this->patch('/admin/refunds/'.$refund->id, ['decision' => 'approved', 'approved_amount' => '20.01', 'decision_note' => 'Reviewing partial refund.'])->assertSessionHasErrors('refund');
        $this->assertSame('requested', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->approved_amount);
    }

    public function test_owner_cannot_approve_while_a_delivery_booking_claim_is_unresolved(): void
    {
        $order = $this->order();
        $order->update(['status' => 'ready', 'delivery_method' => 'lalamove', 'lat' => '14.676', 'lng' => '121.0437']);
        $refund = $this->refund($order);
        $recovery = app(DeliveryRecovery::class);
        $recovery->beginBooking($order);
        $before = $refund->getAttributes();
        $this->actingAs($this->user('admin'))->patch('/admin/refunds/'.$refund->id,
            ['decision' => 'approved', 'approved_amount' => '100', 'decision_note' => 'Reviewing a refund during courier booking.'])
            ->assertSessionHasErrors('refund');
        $this->assertSame('delivery_booking', $order->fresh()->status);
        $this->assertSame($before, $refund->fresh()->getAttributes());

        // A verified pre-POST failure releases the claim and makes review safe again.
        $recovery->rejectBooking($order);
        $this->patch('/admin/refunds/'.$refund->id,
            ['decision' => 'approved', 'approved_amount' => '100', 'decision_note' => 'Courier booking did not start.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertTrue($order->fresh()->fulfillmentOnHold());
        Http::assertNothingSent();
    }

    public function test_owner_can_decline_a_request_while_delivery_booking_is_unresolved(): void
    {
        $order = $this->order();
        $order->update(['status' => 'delivery_booking', 'delivery_method' => 'lalamove']);
        $refund = $this->refund($order);
        $this->actingAs($this->user('admin'))->patch('/admin/refunds/'.$refund->id,
            ['decision' => 'rejected', 'decision_note' => 'The issue does not qualify for a refund.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('rejected', $refund->fresh()->status);
        $this->assertSame('delivery_booking', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_staff_cannot_approve_reject_or_adopt_a_provider_refund(): void
    {
        $refund = $this->refund($this->order());
        $this->actingAs($this->user('staff'));
        foreach (['approved', 'rejected'] as $decision) {
            $this->patch('/admin/refunds/'.$refund->id, ['decision' => $decision, 'approved_amount' => '100', 'decision_note' => 'Staff cannot review requests.'])->assertForbidden();
        }
        $this->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertForbidden();
        $this->assertSame('requested', $refund->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_customers_cannot_access_refund_management_or_another_customers_order(): void
    {
        $ownOrder = $this->order();
        $ownRefund = $this->refund($ownOrder, ['reason' => 'PRIVATE CUSTOMER REFUND REASON']);
        $otherOrder = $this->order();
        $this->actingAs($otherOrder->user)->get('/orders/'.$ownOrder->id)->assertForbidden();
        $this->get('/admin/refunds')->assertForbidden();
        $this->get('/admin/refunds/'.$ownRefund->id)->assertForbidden();
        $this->post('/admin/refunds/'.$ownRefund->id.'/check')->assertForbidden();
        $this->patch('/admin/refunds/'.$ownRefund->id, ['decision' => 'rejected', 'decision_note' => 'An unrelated customer request.'])->assertForbidden();
        $this->post('/admin/refunds/'.$ownRefund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertForbidden();
        $this->actingAs($ownOrder->user)->get('/orders/'.$ownOrder->id)->assertOk()->assertSee('Request #'.$ownRefund->id)->assertSee('Awaiting review');
        Http::assertNothingSent();
    }

    public static function providerStatuses(): array
    {
        return [['pending'], ['processing'], ['succeeded'], ['failed']];
    }

    #[DataProvider('providerStatuses')]
    public function test_owner_verifies_provider_refund_using_get_only_without_rewriting_stock_or_sales(string $status): void
    {
        $order = $this->order();
        $refund = $this->approved($order, 12000);
        $before = $this->unchangedCommerce($order);
        $this->fakeRefund($this->provider($refund, ['status' => $status]));
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertSessionHasNoErrors();
        $refund->refresh();
        $this->assertSame($status, $refund->status);
        $this->assertSame(10000, $refund->amount);
        $this->assertSame('ref_test', $refund->provider_refund_id);
        $this->assertIsInt($refund->provider_updated_at);
        $this->assertSame($status === 'succeeded', $refund->confirmed_at !== null);
        $this->assertSame($before, $this->unchangedCommerce($order));
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api.paymongo.com/refunds/ref_test');
        Http::assertSentCount(1);
    }

    public static function invalidProviderData(): array
    {
        return [['payment'], ['currency'], ['mode'], ['id'], ['amount_type'], ['amount_limit'], ['amount_minimum'], ['status'], ['missing_date'], ['future_date'], ['before_payment_date']];
    }

    #[DataProvider('invalidProviderData')]
    public function test_provider_mismatches_cannot_confirm_or_attach_a_refund(string $invalid): void
    {
        $refund = $this->approved($this->order());
        $data = $this->provider($refund);
        match ($invalid) {
            'payment' => $data['data']['attributes']['payment_id'] = 'pay_another',
            'currency' => $data['data']['attributes']['currency'] = 'USD',
            'mode' => $data['data']['attributes']['livemode'] = true,
            'id' => $data['data']['id'] = 'ref_another',
            'amount_type' => $data['data']['attributes']['amount'] = '10000',
            'amount_limit' => $data['data']['attributes']['amount'] = 10001,
            'amount_minimum' => $data['data']['attributes']['amount'] = 0,
            'status' => $data['data']['attributes']['status'] = 'unknown',
            'missing_date' => $data['data']['attributes']['updated_at'] = null,
            'future_date' => $data['data']['attributes']['updated_at'] = time() + 301,
            'before_payment_date' => $data['data']['attributes']['updated_at'] = 1,
        };
        $before = $refund->getAttributes();
        $this->fakeRefund($data);
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertSessionHasErrors('refund');
        $this->assertSame($before, $refund->fresh()->getAttributes());
    }

    public function test_confirmed_refund_is_idempotent_and_retains_its_original_report_timestamp(): void
    {
        $refund = $this->approved($this->order());
        $first = $this->provider($refund, ['updated_at' => time() - 60]);
        $later = $this->provider($refund, ['updated_at' => time()]);
        Http::fakeSequence()->push($first)->push($later);
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertSessionHasNoErrors();
        $confirmedAt = $refund->fresh()->confirmed_at->getTimestamp();
        $this->actingAs($this->user('staff'))->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasNoErrors();
        $this->assertSame($confirmedAt, $refund->fresh()->confirmed_at->getTimestamp());
        $this->assertSame($later['data']['attributes']['updated_at'], $refund->fresh()->provider_updated_at);
        $this->assertSame(10000, (int) Refund::where('status', 'succeeded')->sum('amount'));
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_provider_cannot_downgrade_a_confirmed_refund_to_failed(): void
    {
        $refund = $this->approved($this->order(), 10000, ['status' => 'succeeded', 'provider_refund_id' => 'ref_test', 'amount' => 10000, 'confirmed_at' => now()->subMinute()]);
        $before = $refund->getAttributes();
        $this->fakeRefund($this->provider($refund, ['status' => 'failed']));
        $this->actingAs($this->user('staff'))->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasErrors('refund');
        $this->assertSame($before, $refund->fresh()->getAttributes());
    }

    public function test_older_provider_processing_response_does_not_reopen_a_failed_refund(): void
    {
        $order = $this->order();
        $refund = $this->approved($order);
        $failed = $this->provider($refund, ['status' => 'failed', 'updated_at' => time() - 60]);
        $older = $this->provider($refund, ['status' => 'processing', 'updated_at' => time() - 300]);
        Http::fakeSequence()->push($failed)->push($older);
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])
            ->assertSessionHasNoErrors();
        $before = $refund->fresh()->getAttributes();
        $this->actingAs($this->user('staff'))->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasNoErrors();
        $this->assertSame($before, $refund->fresh()->getAttributes());
        $this->assertFalse($order->fresh()->fulfillmentOnHold());
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/refunds', ['reason' => 'Retrying the provider failed refund.'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('refunds', 2);
        Http::assertSentCount(2);
    }

    public static function providerRegressions(): array
    {
        return [['failed', 'pending'], ['failed', 'processing'], ['failed', 'succeeded'],
            ['succeeded', 'pending'], ['succeeded', 'processing'], ['succeeded', 'failed'], ['processing', 'pending']];
    }

    #[DataProvider('providerRegressions')]
    public function test_newer_provider_updates_cannot_reverse_a_terminal_outcome_or_processing(string $saved, string $incoming): void
    {
        $order = $this->order();
        $refund = $this->approved($order, 10000, ['status' => $saved, 'provider_refund_id' => 'ref_test', 'amount' => 10000,
            'provider_updated_at' => time() - 60, 'confirmed_at' => $saved === 'succeeded' ? now()->subMinute() : null]);
        $before = $refund->getAttributes();
        $commerce = $this->unchangedCommerce($order);
        $this->fakeRefund($this->provider($refund, ['status' => $incoming, 'updated_at' => time() - 30]));
        $this->actingAs($this->user('staff'))->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasErrors('refund');
        $this->assertSame($before, $refund->fresh()->getAttributes());
        $this->assertSame($commerce, $this->unchangedCommerce($order));
    }

    public static function equalTimestampUpdates(): array
    {
        return [['processing', 10000, false], ['succeeded', 10000, true], ['processing', 9000, true]];
    }

    #[DataProvider('equalTimestampUpdates')]
    public function test_equal_provider_timestamps_allow_only_an_identical_saved_update(string $status, int $amount, bool $conflict): void
    {
        $timestamp = time() - 60;
        $refund = $this->approved($this->order(), 12000, ['status' => 'processing', 'provider_refund_id' => 'ref_test',
            'amount' => 10000, 'provider_updated_at' => $timestamp]);
        $before = $refund->getAttributes();
        $this->fakeRefund($this->provider($refund, ['status' => $status, 'amount' => $amount, 'updated_at' => $timestamp]));
        $response = $this->actingAs($this->user('staff'))->post('/admin/refunds/'.$refund->id.'/check');
        if ($conflict) {
            $response->assertSessionHasErrors('refund');
        } else {
            $response->assertSessionHasNoErrors();
        }
        $this->assertSame($before, $refund->fresh()->getAttributes());
    }

    public function test_newer_provider_update_cannot_change_the_amount_of_an_attached_refund(): void
    {
        $refund = $this->approved($this->order(), 12000, ['status' => 'pending', 'provider_refund_id' => 'ref_test',
            'amount' => 10000, 'provider_updated_at' => time() - 60]);
        $before = $refund->getAttributes();
        $this->fakeRefund($this->provider($refund, ['amount' => 9000, 'updated_at' => time() - 30]));
        $this->actingAs($this->user('staff'))->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasErrors('refund');
        $this->assertSame($before, $refund->fresh()->getAttributes());
    }

    public static function invalidUpdateDates(): array
    {
        $cases = [];
        foreach (['pending', 'processing', 'succeeded', 'failed'] as $status) {
            foreach (['missing', 'string', 'future', 'before_payment'] as $invalid) {
                $cases[$status.'_'.$invalid] = [$status, $invalid];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidUpdateDates')]
    public function test_every_provider_status_requires_an_integer_update_time_between_payment_and_now(string $status, string $invalid): void
    {
        $refund = $this->approved($this->order());
        $before = $refund->getAttributes();
        $timestamp = match ($invalid) {
            'missing' => null,
            'string' => (string) (time() - 60),
            'future' => time() + 60,
            'before_payment' => $refund->order->paid_at->timestamp - 1,
        };
        $this->fakeRefund($this->provider($refund, ['status' => $status, 'updated_at' => $timestamp]));
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])
            ->assertSessionHasErrors('refund');
        $this->assertSame($before, $refund->fresh()->getAttributes());
    }

    public function test_progressing_provider_updates_persist_the_latest_time_and_original_confirmation_date(): void
    {
        $refund = $this->approved($this->order());
        $pendingTime = time() - 300;
        $processingTime = time() - 240;
        $successTime = time() - 180;
        $laterTime = time() - 60;
        Http::fakeSequence()->push($this->provider($refund, ['status' => 'pending', 'updated_at' => $pendingTime]))
            ->push($this->provider($refund, ['status' => 'processing', 'updated_at' => $processingTime]))
            ->push($this->provider($refund, ['status' => 'succeeded', 'updated_at' => $successTime]))
            ->push($this->provider($refund, ['status' => 'succeeded', 'updated_at' => $laterTime]));
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])
            ->assertSessionHasNoErrors();
        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertSame($pendingTime, $refund->fresh()->provider_updated_at);
        $this->actingAs($this->user('staff'))->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasNoErrors();
        $this->assertSame('processing', $refund->fresh()->status);
        $this->assertSame($processingTime, $refund->fresh()->provider_updated_at);
        $this->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasNoErrors();
        $this->assertSame($successTime, $refund->fresh()->confirmed_at->timestamp);
        $this->post('/admin/refunds/'.$refund->id.'/check')->assertSessionHasNoErrors();
        $this->assertSame($laterTime, $refund->fresh()->provider_updated_at);
        $this->assertSame($successTime, $refund->fresh()->confirmed_at->timestamp);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_provider_failure_keeps_refund_and_commerce_unchanged_and_hides_provider_error(): void
    {
        $refund = $this->approved($this->order());
        $before = $refund->getAttributes();
        $commerce = $this->unchangedCommerce($refund->order);
        Http::fake(['https://api.paymongo.com/refunds/*' => Http::response(['message' => 'private-provider-secret'], 500)]);
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertSessionHasErrors('refund');
        $this->assertStringNotContainsString('private-provider-secret', session('errors')->first('refund'));
        $this->assertSame($before, $refund->fresh()->getAttributes());
        $this->assertSame($commerce, $this->unchangedCommerce($refund->order));
    }

    public function test_combined_provider_confirmations_cannot_exceed_original_paid_total(): void
    {
        $order = $this->order();
        $this->approved($order, 12000, ['status' => 'succeeded', 'amount' => 12000, 'provider_refund_id' => 'ref_prior', 'confirmed_at' => now()->subMinute()]);
        $refund = $this->approved($order, 15000);
        $this->fakeRefund($this->provider($refund, ['amount' => 15000]));
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertSessionHasErrors('refund');
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->provider_refund_id);
        $this->assertSame(12000, (int) $order->refunds()->where('status', 'succeeded')->sum('amount'));
    }

    public function test_provider_reference_cannot_be_shared_between_requests_or_changed_after_attachment(): void
    {
        $order = $this->order();
        $this->approved($order, 10000, ['status' => 'failed', 'amount' => 10000, 'provider_refund_id' => 'ref_test']);
        $refund = $this->approved($order);
        $this->fakeRefund($this->provider($refund));
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertSessionHasErrors('refund');
        $this->assertNull($refund->fresh()->provider_refund_id);
        $refund->update(['provider_refund_id' => 'ref_attached', 'status' => 'pending']);
        $this->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertSessionHasErrors('refund');
        $this->assertSame('ref_attached', $refund->fresh()->provider_refund_id);
    }

    public function test_unapproved_request_and_invalid_reference_cannot_be_confirmed(): void
    {
        $refund = $this->refund($this->order());
        $this->fakeRefund($this->provider($refund));
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertSessionHasErrors('refund');
        $this->assertSame('requested', $refund->fresh()->status);
        $this->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'https://attacker.example/refund'])->assertSessionHasErrors('provider_refund_id');
        Http::assertSentCount(1);
    }

    public function test_review_requires_order_to_remain_a_verified_paid_payment(): void
    {
        $order = $this->order();
        $refund = $this->refund($order);
        $order->update(['payment_status' => 'pending']);
        $this->actingAs($this->user('admin'))->patch('/admin/refunds/'.$refund->id, ['decision' => 'approved', 'approved_amount' => '100', 'decision_note' => 'Order payment must remain verified.'])->assertSessionHasErrors('refund');
        $this->assertSame('requested', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->approved_amount);
    }

    public function test_staff_refund_list_filters_and_detail_keep_owner_controls_hidden_and_escape_customer_reason(): void
    {
        $order = $this->order();
        $refund = $this->refund($order, ['reason' => '<script>private reason</script>']);
        $other = $this->refund($this->order(), ['status' => 'rejected']);
        $this->actingAs($this->user('staff'))->get('/admin/refunds?status=requested&q='.$order->number)
            ->assertOk()->assertViewHas('refunds', fn ($refunds) => $refunds->total() === 1 && $refunds->first()->id === $refund->id)
            ->assertSee($order->number)->assertDontSee($other->order->number);
        $this->get('/admin/refunds/'.$refund->id)->assertOk()->assertSee('&lt;script&gt;private reason&lt;/script&gt;', false)
            ->assertDontSee('<script>private reason</script>', false)
            ->assertDontSee('action="'.route('admin.refunds.review', $refund).'"', false)
            ->assertDontSee('action="'.route('admin.refunds.recover', $refund).'"', false);
        Http::assertNothingSent();
    }

    public function test_combined_refunds_may_exactly_equal_payment_but_leave_original_sales_and_inventory_intact(): void
    {
        $order = $this->order();
        $this->approved($order, 10000, ['status' => 'succeeded', 'amount' => 10000, 'provider_refund_id' => 'ref_prior', 'confirmed_at' => now()->subMinute()]);
        $refund = $this->approved($order, 15000);
        $before = $this->unchangedCommerce($order);
        $this->fakeRefund($this->provider($refund, ['amount' => 15000]));
        $this->actingAs($this->user('admin'))->post('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_test'])->assertSessionHasNoErrors();
        $this->assertSame($order->total, (int) $order->refunds()->where('status', 'succeeded')->sum('amount'));
        $this->assertSame($before, $this->unchangedCommerce($order));
        $this->assertTrue($order->fresh()->fulfillmentOnHold());
    }

    public static function fulfillmentHolds(): array
    {
        return [['approved'], ['pending'], ['processing'], ['succeeded']];
    }

    #[DataProvider('fulfillmentHolds')]
    public function test_approved_processing_or_full_refund_stops_packing_and_delivery_booking(string $status): void
    {
        $order = $this->order();
        $order->update(['status' => 'to_pack']);
        $this->approved($order, $order->total, ['status' => $status, 'amount' => $order->total]);
        $this->actingAs($this->user('staff'))->patch('/admin/orders/'.$order->id, ['status' => 'packing'])->assertSessionHasErrors('refund');
        $this->assertSame('to_pack', $order->fresh()->status);
        $order->update(['status' => 'ready', 'delivery_method' => 'lalamove', 'lat' => '14.676', 'lng' => '121.0437']);
        config(['shop.lalamove.mode' => 'sandbox', 'shop.lalamove.key' => 'pk_test_refund_delivery', 'shop.lalamove.secret' => 'sk_test_refund_delivery',
            'shop.lalamove.address' => '10 Store Street', 'shop.lalamove.lat' => '14.65', 'shop.lalamove.lng' => '121.03', 'shop.lalamove.phone' => '+639123456789']);
        $this->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_id);
        Http::assertNothingSent();
    }

    public function test_requested_only_or_partial_confirmed_refund_does_not_automatically_stop_fulfillment(): void
    {
        $order = $this->order();
        $order->update(['status' => 'to_pack']);
        $this->refund($order);
        $this->actingAs($this->user('staff'))->patch('/admin/orders/'.$order->id, ['status' => 'packing'])->assertSessionHasNoErrors();
        $this->assertSame('packing', $order->fresh()->status);
        $other = $this->order();
        $other->update(['status' => 'to_pack']);
        $this->approved($other, 10000, ['status' => 'succeeded', 'amount' => 10000, 'provider_refund_id' => 'ref_partial', 'confirmed_at' => now()->subMinute()]);
        $this->patch('/admin/orders/'.$other->id, ['status' => 'packing'])->assertSessionHasNoErrors();
        $this->assertSame('packing', $other->fresh()->status);
        Http::assertNothingSent();
    }
}
