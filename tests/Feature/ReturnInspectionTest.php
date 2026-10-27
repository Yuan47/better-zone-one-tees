<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnInspection;
use App\Models\User;
use App\Services\ReturnInspections;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReturnInspectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00', 'Asia/Manila'));
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_return_inspection',
            'shop.paymongo.webhook_secret' => 'whsec_return_inspection']);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function order(array $attributes = []): Order
    {
        $product = Product::create(['name' => 'Returned tee', 'slug' => 'returned-'.Str::uuid(), 'category' => '', 'description' => 'Return test fixture']);
        $variant = $product->variants()->create(['sku' => 'RETURN-'.Str::uuid(), 'size' => 'M', 'color' => 'White', 'stock' => 8,
            'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
        $order = Order::create($attributes + ['number' => 'RETURN-'.Str::uuid(), 'user_id' => User::factory()->create()->id,
            'tier' => 'retail', 'quantity' => 2, 'merchandise_base' => 20000, 'discount' => 0, 'subtotal' => 20000,
            'shipping' => 5000, 'total' => 25000, 'recipient' => 'Return buyer', 'phone' => '09123456789', 'address' => 'Store pickup',
            'delivery_method' => 'pickup', 'payment_status' => 'paid', 'amount_paid' => 25000, 'status' => 'completed',
            'paid_at' => now()->subDays(2), 'customer_confirmed_at' => now()->subDay(), 'inventory_committed' => true]);
        $order->items()->create(['variant_id' => $variant->id, 'name' => $product->name, 'size' => 'M', 'color' => 'White',
            'quantity' => 2, 'unit_price' => 10000, 'total' => 20000]);

        return $order->fresh();
    }

    private function receipt(Order $order, string $method = 'cash', ?int $amount = null): PaymentAttempt
    {
        return $order->payments()->create(['reference' => (string) Str::uuid(), 'method' => $method, 'status' => 'paid',
            'amount' => $amount ?? $order->total, 'received_at' => now()->subDays(2),
            'payment_id' => $method === 'paymongo' ? 'pay_'.Str::random(16) : null]);
    }

    private function requestRefund(Order $order, array $attributes = []): Refund
    {
        return $order->refunds()->create($attributes + ['requested_by' => $order->user_id, 'requested_amount' => Refund::remainingForOrder($order),
            'reason' => 'The returned clothing does not fit as expected.', 'status' => 'requested'] + app(ReturnInspections::class)->snapshot($order));
    }

    private function inspectionData(Order $order, array $attributes = []): array
    {
        return $attributes + ['items' => [['order_item_id' => $order->items()->sole()->id, 'quantity' => 2, 'condition' => 'good']],
            'include_delivery' => '1', 'inspection_note' => 'Returned clothing physically counted and checked against the invoice.',
            'photo_exception_reason' => 'No inspection photograph is available for this supervised test return.'];
    }

    private function inspect(Refund $refund, User $owner, array $attributes = []): void
    {
        $this->actingAs($owner)->post('/admin/refunds/'.$refund->id.'/inspection', $this->inspectionData($refund->order, $attributes))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function approve(Refund $refund, PaymentAttempt $receipt, User $owner, ?int $amount = null): void
    {
        $this->actingAs($owner)->patch('/admin/refunds/'.$refund->id, ['decision' => 'approved',
            'approved_amount' => number_format(($amount ?? $receipt->amount) / 100, 2, '.', ''),
            'payment_attempt_id' => $receipt->id, 'decision_note' => 'Returned items inspected and this original receipt refund is approved.'])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function provider(PaymentAttempt $receipt, int $amount, string $id = 'ref_inspected'): void
    {
        Http::fake(['https://api.paymongo.com/refunds/'.$id => Http::response(['data' => ['id' => $id,
            'attributes' => ['payment_id' => $receipt->payment_id, 'amount' => $amount, 'currency' => 'PHP', 'livemode' => false,
                'status' => 'succeeded', 'updated_at' => now()->timestamp]]])]);
    }

    public function test_customer_photo_is_private_and_request_keeps_its_original_policy_snapshot(): void
    {
        $order = $this->order();
        $this->receipt($order);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/refunds', ['reason' => 'The clothing needs to be returned for a sizing issue.',
            'customer_photo' => UploadedFile::fake()->image('customer.jpg'), 'approved_amount' => '250', 'policy_days' => 30])
            ->assertRedirect()->assertSessionHasNoErrors();
        $refund = Refund::sole();
        $this->assertSame(7, $refund->policy_days);
        $this->assertTrue($refund->policy_delivery_eligible);
        $this->assertFalse($refund->policy_late);
        $this->assertEquals($order->customer_confirmed_at->copy()->addDays(7), $refund->policy_deadline_at);
        $this->assertNull($refund->approved_amount);
        Storage::disk('local')->assertExists($refund->customer_photo_path);
        $this->get('/admin/refunds/'.$refund->id.'/evidence/customer')->assertForbidden();
        $this->actingAs($this->owner())->get('/admin/refunds/'.$refund->id.'/evidence/customer')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get('/admin/refunds/'.$refund->id.'/evidence/customer')->assertOk();
        Http::assertNothingSent();
    }

    public function test_guest_request_accepts_optional_private_photo_only_through_a_valid_invoice_link(): void
    {
        $order = $this->order(['user_id' => null, 'sales_channel' => 'walk_in', 'customer_confirmed_at' => null, 'walk_in_received_at' => now()->subDay()]);
        $this->receipt($order);
        $url = URL::temporarySignedRoute('guest.orders.refunds', now()->addHour(), ['order' => $order->id]);
        $this->post($url, ['reason' => 'The clothing was returned to the store for the owner inspection.', 'customer_photo' => UploadedFile::fake()->image('guest.png')])
            ->assertRedirect()->assertSessionHasNoErrors();
        $refund = Refund::sole();
        $this->assertNull($refund->requested_by);
        $this->assertSame(7, $refund->policy_days);
        Storage::disk('local')->assertExists($refund->customer_photo_path);
        $this->get('/admin/refunds/'.$refund->id.'/evidence/customer')->assertRedirect('/admin/login');
    }

    public function test_approval_cash_and_new_provider_attachment_are_blocked_without_inspection(): void
    {
        $order = $this->order();
        $cash = $this->receipt($order, 'cash', 10000);
        $online = $this->receipt($order, 'paymongo', 15000);
        $refund = $this->requestRefund($order);
        $owner = $this->owner();
        $this->actingAs($owner)->patchJson('/admin/refunds/'.$refund->id, ['decision' => 'approved', 'approved_amount' => '100',
            'payment_attempt_id' => $cash->id, 'decision_note' => 'An approval still requires its physical inspection.'])->assertUnprocessable();
        $this->assertSame('requested', $refund->fresh()->status);
        $refund->update(['status' => 'approved', 'approved_amount' => 10000, 'payment_attempt_id' => $cash->id]);
        $this->postJson('/admin/refunds/'.$refund->id.'/cash', ['payment_attempt_id' => $cash->id, 'cash_reason' => 'Uninspected cash must not be recorded as returned.'])->assertUnprocessable();
        $refund->update(['payment_attempt_id' => $online->id, 'approved_amount' => 15000]);
        $this->postJson('/admin/refunds/'.$refund->id.'/recover', ['provider_refund_id' => 'ref_withoutinspection', 'payment_attempt_id' => $online->id])->assertUnprocessable();
        $this->assertNull($refund->fresh()->amount);
        $this->assertNull($refund->fresh()->provider_refund_id);
        Http::assertNothingSent();
    }

    public static function invalidInspections(): array
    {
        return [['foreign_item'], ['duplicate_item'], ['excess_quantity'], ['zero_quantity'], ['invalid_condition'], ['no_note'], ['no_evidence']];
    }

    #[DataProvider('invalidInspections')]
    public function test_invalid_physical_returns_do_not_create_an_inspection(string $case): void
    {
        $order = $this->order();
        $this->receipt($order);
        $refund = $this->requestRefund($order);
        $data = $this->inspectionData($order);
        if ($case === 'foreign_item') {
            $data['items'][0]['order_item_id'] = $this->order()->items()->sole()->id;
        } elseif ($case === 'duplicate_item') {
            $data['items'][0]['quantity'] = 1;
            $data['items'][] = $data['items'][0];
        } elseif ($case === 'excess_quantity') {
            $data['items'][0]['quantity'] = 3;
        } elseif ($case === 'zero_quantity') {
            $data['items'][0]['quantity'] = 0;
        } elseif ($case === 'invalid_condition') {
            $data['items'][0]['condition'] = 'not_checked';
        } elseif ($case === 'no_note') {
            $data['inspection_note'] = null;
        } else {
            $data['photo_exception_reason'] = null;
        }
        $this->actingAs($this->owner())->postJson('/admin/refunds/'.$refund->id.'/inspection', $data)->assertUnprocessable();
        $this->assertDatabaseCount('return_inspections', 0);
        $this->assertNull($refund->fresh()->return_inspection_id);
        Http::assertNothingSent();
    }

    public function test_inspection_photo_is_required_unless_the_owner_records_a_reason_and_action_replay_is_safe(): void
    {
        $order = $this->order();
        $this->receipt($order);
        $refund = $this->requestRefund($order);
        $owner = $this->owner();
        $data = $this->inspectionData($order, ['photo_exception_reason' => null, 'inspection_photo' => UploadedFile::fake()->image('inspection.webp')]);
        $this->actingAs($owner)->post('/admin/refunds/'.$refund->id.'/inspection', $data)->assertRedirect()->assertSessionHasNoErrors();
        $case = ReturnInspection::sole();
        Storage::disk('local')->assertExists($case->photo_path);
        $this->assertNull($case->photo_exception_reason);
        $this->get('/admin/refunds/'.$refund->id.'/evidence/inspection')->assertOk();
        $this->postJson('/admin/refunds/'.$refund->id.'/inspection', $this->inspectionData($order))->assertUnprocessable();
        $this->assertDatabaseCount('return_inspections', 1);
        $this->assertSame(1, ActivityLog::where('action', 'refund.inspected')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_invalid_or_oversized_image_uploads_are_rejected_without_public_files(): void
    {
        $order = $this->order();
        $this->receipt($order);
        $refund = $this->requestRefund($order);
        $owner = $this->owner();
        foreach ([UploadedFile::fake()->create('attack.svg', 1, 'image/svg+xml'), UploadedFile::fake()->image('too-large.jpg')->size(3073)] as $photo) {
            $this->actingAs($owner)->post('/admin/refunds/'.$refund->id.'/inspection', $this->inspectionData($order, ['inspection_photo' => $photo]))
                ->assertSessionHasErrors('inspection_photo');
        }
        $this->assertDatabaseCount('return_inspections', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_late_requests_are_saved_but_need_an_owner_exception_before_approval(): void
    {
        $order = $this->order(['customer_confirmed_at' => now()->subDays(8)]);
        $receipt = $this->receipt($order);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/refunds', ['reason' => 'Please review the late return because of an unresolved sizing issue.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $refund = Refund::sole();
        $this->assertTrue($refund->policy_late);
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/admin/refunds/'.$refund->id.'/inspection', $this->inspectionData($order))->assertUnprocessable();
        $this->inspect($refund, $owner, ['late_exception_reason' => 'Owner accepted the late return because the customer reported the defect earlier.']);
        $this->assertNotNull($refund->fresh()->late_exception_reason);
        $this->assertNotNull(ReturnInspection::sole()->late_exception_reason);
        $this->approve($refund->fresh(), $receipt, $owner);
        $this->assertSame('approved', $refund->fresh()->status);
    }

    public function test_policy_changes_are_owner_only_and_do_not_rewrite_existing_requests(): void
    {
        $order = $this->order();
        $this->receipt($order);
        $old = $this->requestRefund($order);
        $payload = ['days' => 14, 'delivery_eligible' => '0', 'reason' => 'Owner changed the return window for future customer requests.'];
        $this->actingAs(User::factory()->create(['role' => 'staff']))->postJson('/admin/refund-policy', $payload)->assertForbidden();
        $this->actingAs($order->user)->postJson('/admin/refund-policy', $payload)->assertForbidden();
        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/refund-policy', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['days' => 14, 'delivery_eligible' => false], app(ReturnInspections::class)->policy());
        $this->assertSame(7, $old->fresh()->policy_days);
        $this->assertTrue($old->fresh()->policy_delivery_eligible);
        $newOrder = $this->order();
        $this->receipt($newOrder);
        $new = $this->requestRefund($newOrder);
        $this->inspect($new, $owner);
        $this->assertSame(14, $new->fresh()->policy_days);
        $this->assertSame(0, $new->fresh()->returnInspection->delivery_value);
        $this->assertSame(1, ActivityLog::where('action', 'refund.policy_updated')->count());
    }

    public function test_discounted_item_values_limit_approval_and_physical_quantities_and_delivery_are_counted_once(): void
    {
        $order = $this->order(['tier' => 'bulk', 'discount' => 1000, 'subtotal' => 19000, 'total' => 24000, 'amount_paid' => 24000]);
        $receipt = $this->receipt($order);
        $owner = $this->owner();
        $first = $this->requestRefund($order);
        $items = [['order_item_id' => $order->items()->sole()->id, 'quantity' => 1, 'condition' => 'good']];
        $this->inspect($first, $owner, ['items' => $items, 'include_delivery' => false]);
        $this->assertSame(9500, $first->fresh()->returnInspection->clothing_value);
        $this->actingAs($owner)->patchJson('/admin/refunds/'.$first->id, ['decision' => 'approved', 'approved_amount' => '96',
            'payment_attempt_id' => $receipt->id, 'decision_note' => 'This amount exceeds the inspected discounted item value.'])->assertUnprocessable();
        $this->approve($first->fresh(), $receipt, $owner, 9500);
        $this->post('/admin/refunds/'.$first->id.'/cash', ['payment_attempt_id' => $receipt->id, 'cash_reason' => 'Owner handed back the approved cash for this returned piece.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $second = $this->requestRefund($order);
        $this->postJson('/admin/refunds/'.$second->id.'/inspection', $this->inspectionData($order))->assertUnprocessable();
        $this->inspect($second, $owner, ['items' => $items]);
        $this->assertSame(9500, $second->fresh()->returnInspection->clothing_value);
        $this->assertSame(5000, $second->fresh()->returnInspection->delivery_value);
        $this->assertSame(19000, (int) ReturnInspection::sum('clothing_value'));
        $this->assertSame(5000, (int) ReturnInspection::sum('delivery_value'));
        $this->assertSame(2, ReturnInspection::count());
    }

    public function test_one_immutable_inspection_can_refund_two_installment_receipts_without_double_returning_clothing(): void
    {
        $order = $this->order(['payment_plan' => 'partial', 'due_at' => now()->addDays(7), 'original_due_at' => now()->addDays(7)]);
        $cash = $this->receipt($order, 'cash', 10000);
        $online = $this->receipt($order, 'paymongo', 15000);
        $original = $order->getRawOriginal();
        $owner = $this->owner();
        $first = $this->requestRefund($order);
        $this->inspect($first, $owner);
        $inspection = ReturnInspection::sole();
        $snapshot = $inspection->getRawOriginal();
        $this->approve($first->fresh(), $cash, $owner);
        $this->post('/admin/refunds/'.$first->id.'/cash', ['payment_attempt_id' => $cash->id, 'cash_reason' => 'Owner handed the approved cash refund back to the buyer.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $second = $this->requestRefund($order);
        $this->post('/admin/refunds/'.$second->id.'/inspection', ['existing_inspection_id' => $inspection->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->approve($second->fresh(), $online, $owner);
        $this->provider($online, 15000);
        $this->post('/admin/refunds/'.$second->id.'/recover', ['payment_attempt_id' => $online->id, 'provider_refund_id' => 'ref_inspected'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, ReturnInspection::count());
        $this->assertSame($snapshot, $inspection->fresh()->getRawOriginal());
        $this->assertSame($original, $order->fresh()->getRawOriginal());
        $this->assertSame(10000, $first->fresh()->clothing_amount);
        $this->assertSame(0, $first->fresh()->delivery_amount);
        $this->assertSame(10000, $second->fresh()->clothing_amount);
        $this->assertSame(5000, $second->fresh()->delivery_amount);
        $this->assertSame(['clothing' => 0, 'delivery' => 0], $inspection->fresh()->remaining());
        $this->assertSame(0, Refund::remainingForOrder($order->fresh()));
        $this->assertSame(2, $order->payments()->where('status', 'paid')->count());
    }

    public function test_reusing_an_inspection_does_not_bypass_a_new_late_request_exception(): void
    {
        $order = $this->order();
        $receipt = $this->receipt($order);
        $owner = $this->owner();
        $first = $this->requestRefund($order);
        $this->inspect($first, $owner);
        $case = ReturnInspection::sole();
        $this->actingAs($owner)->patch('/admin/refunds/'.$first->id, ['decision' => 'rejected', 'decision_note' => 'Original request withdrawn; retained inspection documents the actual returned items.'])->assertRedirect();
        $this->travel(8)->days();
        $second = $this->requestRefund($order);
        $this->assertTrue($second->policy_late);
        $this->postJson('/admin/refunds/'.$second->id.'/inspection', ['existing_inspection_id' => $case->id])->assertUnprocessable();
        $this->post('/admin/refunds/'.$second->id.'/inspection', ['existing_inspection_id' => $case->id,
            'late_exception_reason' => 'Owner accepts this follow-up request against clothing already held by the store.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($second->fresh()->late_exception_reason);
        $this->approve($second->fresh(), $receipt, $owner);
        $this->assertSame(1, ReturnInspection::count());
    }

    public function test_foreign_inspections_and_customer_or_staff_inspection_actions_are_rejected(): void
    {
        $order = $this->order();
        $this->receipt($order);
        $refund = $this->requestRefund($order);
        $owner = $this->owner();
        $this->inspect($refund, $owner);
        $other = $this->order();
        $this->receipt($other);
        $otherRefund = $this->requestRefund($other);
        $this->postJson('/admin/refunds/'.$otherRefund->id.'/inspection', ['existing_inspection_id' => $refund->fresh()->return_inspection_id])->assertUnprocessable();
        foreach ([$order->user, User::factory()->create(['role' => 'staff'])] as $actor) {
            $this->actingAs($actor)->postJson('/admin/refunds/'.$otherRefund->id.'/inspection', $this->inspectionData($other))->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get('/admin/refunds/'.$otherRefund->id)->assertOk()
            ->assertDontSee('Save physical inspection')->assertDontSee('Save decision');
        $this->assertNull($otherRefund->fresh()->return_inspection_id);
    }

    public function test_verified_smaller_provider_refund_uses_actual_allocation_without_exceeding_the_approval_or_changing_attached_amount(): void
    {
        $order = $this->order();
        $receipt = $this->receipt($order, 'paymongo');
        $refund = $this->requestRefund($order);
        $owner = $this->owner();
        $this->inspect($refund, $owner);
        $this->approve($refund->fresh(), $receipt, $owner);
        $response = fn (int $amount) => ['data' => ['id' => 'ref_inspected', 'attributes' => [
            'payment_id' => $receipt->payment_id, 'amount' => $amount, 'currency' => 'PHP', 'livemode' => false,
            'status' => 'succeeded', 'updated_at' => now()->timestamp]]];
        Http::fake(['https://api.paymongo.com/refunds/ref_inspected' => Http::sequence()
            ->push($response(26000))->push($response(20000))->push($response(21000))]);
        $this->postJson('/admin/refunds/'.$refund->id.'/recover', ['payment_attempt_id' => $receipt->id, 'provider_refund_id' => 'ref_inspected'])->assertUnprocessable();
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->provider_refund_id);
        $this->assertSame(20000, $refund->fresh()->clothing_amount);
        $this->assertSame(5000, $refund->fresh()->delivery_amount);
        $this->post('/admin/refunds/'.$refund->id.'/recover', ['payment_attempt_id' => $receipt->id, 'provider_refund_id' => 'ref_inspected'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertSame(25000, $refund->fresh()->approved_amount);
        $this->assertSame(20000, $refund->fresh()->amount);
        $this->assertSame(20000, $refund->fresh()->clothing_amount);
        $this->assertSame(0, $refund->fresh()->delivery_amount);
        $this->assertSame(['clothing' => 0, 'delivery' => 5000], $refund->fresh()->returnInspection->remaining());
        $this->assertSame(5000, Refund::remainingForOrder($order->fresh()));
        $this->get('/admin/refunds/'.$refund->id)->assertOk()
            ->assertSee('Return value left after approved and confirmed refunds: ₱50.00');
        $this->postJson('/admin/refunds/'.$refund->id.'/check')->assertUnprocessable();
        $this->assertSame(20000, $refund->fresh()->amount);
        $this->assertSame(0, $refund->fresh()->delivery_amount);
        Http::assertSentCount(3);
    }

    public function test_customer_and_guest_can_see_their_saved_return_deadline_and_late_exception_after_a_policy_change(): void
    {
        $owner = $this->owner();
        $orders = [$this->order(['customer_confirmed_at' => now()->subDays(8)]),
            $this->order(['user_id' => null, 'sales_channel' => 'walk_in', 'customer_confirmed_at' => null,
                'walk_in_received_at' => now()->subDays(8)])];
        $exception = 'Owner accepted this late return because the defect was reported before the deadline.';
        foreach ($orders as $order) {
            $this->receipt($order);
            $refund = $this->requestRefund($order);
            $this->inspect($refund, $owner, ['late_exception_reason' => $exception]);
        }
        $this->actingAs($owner)->post('/admin/refund-policy', ['days' => 14, 'delivery_eligible' => '0',
            'reason' => 'Owner changed the current policy for future requests only.'])->assertRedirect()->assertSessionHasNoErrors();
        $customerResponse = $this->actingAs($orders[0]->user)->get('/orders/'.$orders[0]->id);
        $this->app['auth']->forgetGuards();
        $guestResponse = $this->get(URL::temporarySignedRoute('guest.orders.show', now()->addHour(), ['order' => $orders[1]->id]));
        foreach ([$customerResponse, $guestResponse] as $response) {
            $response->assertOk()->assertSee('Send your request within 14 days')
                ->assertSee('Original return window: 7 days')
                ->assertSee(now()->subDay()->format('M d, Y · g:i A'))
                ->assertSee('Submitted after the return window.')
                ->assertSee($exception);
        }
    }

    public function test_existing_issued_provider_refunds_remain_reconcilable_without_rewriting_historical_inspections(): void
    {
        $order = $this->order();
        $receipt = $this->receipt($order, 'paymongo');
        $refund = $this->requestRefund($order, ['status' => 'processing', 'provider_refund_id' => 'ref_historical',
            'approved_amount' => 10000, 'amount' => 10000, 'payment_attempt_id' => $receipt->id, 'provider_updated_at' => now()->subMinute()->timestamp]);
        $this->provider($receipt, 10000, 'ref_historical');
        $this->actingAs($this->owner())->post('/admin/refunds/'.$refund->id.'/check')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->return_inspection_id);
        $this->assertNull($refund->fresh()->clothing_amount);
        $this->assertDatabaseCount('return_inspections', 0);
    }

    public function test_recorded_inspection_cannot_be_edited_or_deleted(): void
    {
        $order = $this->order();
        $this->receipt($order);
        $refund = $this->requestRefund($order);
        $this->inspect($refund, $this->owner());
        $case = ReturnInspection::sole();
        foreach (['update', 'delete'] as $action) {
            try {
                $action === 'update' ? $case->update(['clothing_value' => 999999]) : $case->delete();
                $this->fail('A permanent physical inspection must not change.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('inspection', $exception->getMessage());
            }
        }
        $this->assertSame(20000, $case->fresh()->clothing_value);
        $this->assertDatabaseCount('return_inspections', 1);
    }
}
