<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChatbotFaq;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryCostApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.lalamove.mode' => 'sandbox', 'shop.lalamove.key' => 'pk_test_delivery', 'shop.lalamove.secret' => 'sk_test_delivery',
            'shop.lalamove.address' => 'Store test address', 'shop.lalamove.lat' => '14.65', 'shop.lalamove.lng' => '121.03',
            'shop.lalamove.phone' => '09123456789', 'shop.lalamove.service' => 'MOTORCYCLE']);
        Http::preventStrayRequests();
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function order(): Order
    {
        return Order::create(['number' => 'ZOT-COST-'.uniqid(), 'user_id' => $this->actor('customer')->id,
            'tier' => 'retail', 'quantity' => 2, 'subtotal' => 30000, 'merchandise_base' => 30000, 'discount' => 0,
            'shipping' => 10000, 'total' => 40000, 'payment_status' => 'paid', 'amount_paid' => 40000,
            'payment_id' => 'pay_cost_test', 'paid_at' => now(), 'status' => 'ready', 'inventory_committed' => true,
            'recipient' => 'Test buyer', 'phone' => '09123456780', 'address' => 'Buyer test address',
            'delivery_method' => 'lalamove', 'lat' => '14.676', 'lng' => '121.0437'])->fresh();
    }

    private function approval(array $changes = []): array
    {
        return $changes + ['courier_cost_limit' => '120.50', 'courier_cost_reason' => 'Store covers the higher fresh courier quote.', 'cover_courier_difference' => '1'];
    }

    private function provider(string $cost = '110.25', string $status = 'ASSIGNING_DRIVER'): void
    {
        Http::fake([
            '*/v3/quotations' => Http::response(['data' => ['quotationId' => 'cost_quote', 'expiresAt' => now()->addMinutes(5)->toIso8601String(),
                'priceBreakdown' => ['currency' => 'PHP', 'total' => $cost], 'stops' => [['stopId' => 'pickup'], ['stopId' => 'buyer']]]]),
            '*/v3/orders' => Http::response(['data' => ['orderId' => 'cost_booking', 'status' => $status]]),
        ]);
    }

    private function financial(Order $order): array
    {
        return $order->fresh()->only(['user_id', 'quantity', 'subtotal', 'merchandise_base', 'discount', 'shipping', 'total', 'amount_paid',
            'payment_status', 'payment_id', 'paid_at', 'due_at', 'original_due_at', 'inventory_committed', 'inventory_released']);
    }

    public function test_owner_approves_a_single_bounded_booking_without_changing_customer_finances(): void
    {
        $order = $this->order();
        $before = $this->financial($order);
        $owner = $this->actor('admin');
        $this->provider();
        $this->actingAs($owner)->post('/admin/orders/'.$order->id.'/delivery', $this->approval())
            ->assertRedirect()->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('out_for_delivery', $order->status);
        $this->assertSame(12050, $order->delivery_booking_context['store_cost_approval']['max_total']);
        $this->assertSame($owner->id, $order->delivery_booking_context['store_cost_approval']['approved_by']);
        $this->assertSame(11025, $order->delivery_booking_context['quoted_cost']);
        $this->assertEquals($before, $this->financial($order));
        $approval = ActivityLog::where('action', 'order.delivery_cost_approved')->sole();
        $this->assertSame(12050, $approval->metadata['amount']);
        $this->assertSame($this->approval()['courier_cost_reason'], $approval->metadata['reason']);
        $this->assertSame(11025, ActivityLog::where('action', 'order.delivery_booked')->sole()->metadata['amount']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/orders') && $request['data']['quotationId'] === 'cost_quote');

        $this->post('/admin/orders/'.$order->id.'/delivery', $this->approval())->assertSessionHasErrors('delivery');
        Http::assertSentCount(2);
        $this->assertSame(1, ActivityLog::where('action', 'order.delivery_cost_approved')->count());
        $this->assertEquals($before, $this->financial($order));
        $this->actingAs($order->user)->get('/orders/'.$order->id)->assertOk()
            ->assertDontSeeText('Owner-approved maximum courier cost')->assertDontSeeText('Booking quote:');
    }

    public static function unauthorizedRoles(): array
    {
        return [['staff'], ['customer']];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_nonowners_cannot_approve_costs_or_make_provider_requests(string $role): void
    {
        $order = $this->order();
        $before = $order->getRawOriginal();
        $this->actingAs($role === 'customer' ? $order->user : $this->actor($role))
            ->post('/admin/orders/'.$order->id.'/delivery', $this->approval())->assertForbidden();
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public static function invalidApprovals(): array
    {
        return [
            [['courier_cost_limit' => '100.00']], [['courier_cost_limit' => '99.99']],
            [['courier_cost_limit' => '-1']], [['courier_cost_limit' => '1e3']],
            [['courier_cost_limit' => '110.001']], [['courier_cost_limit' => '42949672.96']],
            [['courier_cost_reason' => 'short']], [['courier_cost_reason' => '          ']],
            [['cover_courier_difference' => '0']], [['cover_courier_difference' => null]],
        ];
    }

    #[DataProvider('invalidApprovals')]
    public function test_invalid_approval_leaves_order_ready_without_network_or_audit_changes(array $changes): void
    {
        $order = $this->order();
        $before = $order->getRawOriginal();
        $this->actingAs($this->actor('admin'))->post('/admin/orders/'.$order->id.'/delivery', $this->approval($changes))
            ->assertSessionHasErrors();
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_quote_above_owner_limit_is_blocked_and_approval_cannot_be_reused_by_staff(): void
    {
        $order = $this->order();
        $before = $this->financial($order);
        $this->provider('150');
        $this->actingAs($this->actor('admin'))->post('/admin/orders/'.$order->id.'/delivery', $this->approval())
            ->assertSessionHasErrors('delivery');
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_booking_context);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertEquals($before, $this->financial($order));

        $this->actingAs($this->actor('staff'))->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        Http::assertSentCount(2);
        $this->assertSame(1, ActivityLog::where('action', 'order.delivery_cost_approved')->count());
    }

    public function test_a_canceled_booking_does_not_carry_approval_into_a_replacement(): void
    {
        $order = $this->order();
        $this->provider('110', 'CANCELED');
        $this->actingAs($this->actor('admin'))->post('/admin/orders/'.$order->id.'/delivery', $this->approval())
            ->assertSessionHasNoErrors();
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivery_booking_context['store_cost_approval']);
        $this->actingAs($this->actor('staff'))->post('/admin/orders/'.$order->id.'/delivery')->assertSessionHasErrors('delivery');
        $this->assertNull($order->fresh()->delivery_booking_context);
        $this->assertDatabaseHas('delivery_attempts', ['provider_delivery_id' => 'cost_booking', 'provider_status' => 'CANCELED']);
        Http::assertSentCount(3);
    }

    public function test_approval_audit_failure_rolls_back_the_claim_before_booking(): void
    {
        $order = $this->order();
        $before = $order->getRawOriginal();
        $this->mock(ActivityRecorder::class, fn ($mock) => $mock->shouldReceive('record')->once()->andReturnNull());
        $this->actingAs($this->actor('admin'))->post('/admin/orders/'.$order->id.'/delivery', $this->approval())->assertServerError();
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_only_the_owner_sees_the_extra_cost_approval_form(): void
    {
        $order = $this->order();
        $this->actingAs($this->actor('admin'))->get('/orders/'.$order->id)->assertOk()->assertSee('Approve a higher courier cost');
        $this->actingAs($this->actor('staff'))->get('/orders/'.$order->id)->assertOk()
            ->assertDontSee('Approve a higher courier cost')->assertSee('ask the owner to approve covering the difference');
        $this->actingAs($order->user)->get('/orders/'.$order->id)->assertOk()->assertDontSee('Approve a higher courier cost');
        Http::assertNothingSent();
    }

    public function test_cost_guides_keep_their_audiences_and_preserve_owner_edits(): void
    {
        $staff = ChatbotFaq::where('question', 'What should staff do if the courier quote increases?')->sole();
        $owner = ChatbotFaq::where('question', 'How does the owner cover an increased courier quote?')->sole();
        $this->assertSame('staff', $staff->audience);
        $this->assertSame('owner', $owner->audience);
        $owner->update(['answer' => 'Owner edited instructions.', 'active' => false]);
        $before = ChatbotFaq::orderBy('id')->get()->toArray();
        $migration = require database_path('migrations/2026_11_01_000023_add_courier_cost_recovery_guides.php');
        $migration->up();
        $migration->down();
        $this->assertSame($before, ChatbotFaq::orderBy('id')->get()->toArray());
        $this->actingAs($this->actor('staff'))->postJson('/chat', ['message' => $staff->question, 'side' => 'staff'])
            ->assertOk()->assertJsonPath('reply', $staff->answer);
        Http::assertNothingSent();
    }
}
