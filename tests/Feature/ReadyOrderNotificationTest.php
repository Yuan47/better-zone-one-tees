<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReadyNotification;
use App\Models\Refund;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\ReadyOrderNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReadyOrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.brevo.delivery_notifications_enabled' => true, 'services.brevo.api_key' => 'brevo-ready-test-key',
            'services.brevo.from_email' => 'store@example.test', 'services.brevo.from_name' => 'Zone One Tees']);
        Http::preventStrayRequests();
    }

    private function actor(string $role = 'staff'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::create($attributes + ['number' => 'ZOT-READY-'.Str::random(8), 'user_id' => $this->actor('customer')->id,
            'quantity' => 2, 'tier' => 'retail', 'subtotal' => 20000, 'merchandise_base' => 20000, 'total' => 25000, 'discount' => 0, 'shipping' => 5000,
            'payment_status' => 'paid', 'amount_paid' => 25000, 'payment_id' => 'pay_'.Str::random(12), 'paid_at' => now(),
            'status' => 'packing', 'inventory_committed' => true, 'inventory_released' => false,
            'recipient' => 'Ready customer', 'phone' => '09123456789', 'address' => 'Customer address', 'delivery_method' => 'lalamove']);
    }

    private function markReady(Order $order, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->actor())->from(route('orders.show', $order))->patch(route('admin.orders.status', $order), [
            'status' => 'ready', 'reason' => 'All sizes and colors have been packed and checked.',
            'recipient' => 'Attacker', 'email' => 'attacker@example.test', 'contact_email' => 'attacker@example.test',
        ]);
    }

    private function queue(Order $order): void
    {
        $order->update(['status' => 'ready']);
        DB::transaction(function () use ($order) {
            app(ReadyOrderNotifications::class)->enqueue(Order::whereKey($order->id)->lockForUpdate()->firstOrFail());
        });
    }

    private function accepted(): void
    {
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<ready-'.Str::random(10).'@brevo.test>'], 201)]);
    }

    public static function methods(): array
    {
        return [['pickup'], ['lalamove']];
    }

    #[DataProvider('methods')]
    public function test_audited_paid_ready_change_sends_once_to_saved_customer_with_truthful_fulfillment_copy(string $method): void
    {
        $buyer = $this->actor('customer');
        $buyer->update(['name' => '<script>Buyer</script>', 'email' => 'actual-customer@example.test']);
        $order = $this->order(['user_id' => $buyer->id, 'delivery_method' => $method, 'contact_email' => 'stale@example.test']);
        $before = $order->getAttributes();
        $actor = $this->actor();
        $this->accepted();
        $this->markReady($order, $actor)->assertRedirect(route('orders.show', $order))->assertSessionHasNoErrors();
        $notification = OrderReadyNotification::sole();
        $this->assertSame('sent', $notification->status);
        $this->assertSame('actual-customer@example.test', $notification->recipient);
        $this->assertSame(1, $notification->attempts);
        $this->assertNotNull($notification->sent_at);
        $this->assertSame('ready', $order->fresh()->status);
        foreach (['payment_status', 'payment_id', 'total', 'amount_paid', 'inventory_committed', 'inventory_released'] as $field) {
            $this->assertSame($before[$field], $order->getAttributes()[$field]);
        }
        $this->assertDatabaseHas('activity_logs', ['actor_id' => $actor->id, 'action' => 'order.status_changed', 'target_id' => $order->id]);
        Http::assertSent(function ($request) use ($method, $order, $notification) {
            $body = $request->data();
            $this->assertSame([['email' => 'actual-customer@example.test', 'name' => '<script>Buyer</script>']], $body['to']);
            $this->assertSame($notification->claim_key, $body['headers']['IdempotencyKey']);
            $this->assertStringContainsString('&lt;script&gt;Buyer&lt;/script&gt;', $body['htmlContent']);
            $this->assertStringNotContainsString('<script>Buyer</script>', $body['htmlContent']);
            $this->assertStringContainsString(route('orders.show', $order), $body['htmlContent']);
            $this->assertStringContainsString($method === 'pickup' ? 'ready for store pickup' : 'ready for delivery', $body['subject']);
            $this->assertStringContainsString($method === 'pickup' ? 'ready for collection' : 'does not mean a courier has collected', $body['textContent']);

            return $request->url() === 'https://api.brevo.com/v3/smtp/email';
        });
        // Repeated staff changes and delivery-recovery-like returns to ready cannot send a second email.
        $this->markReady($order, $actor)->assertStatus(422);
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($order->id));
        $order->update(['status' => 'ready']);
        app(ReadyOrderNotifications::class)->enqueue($order->fresh());
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($order->id));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('order_ready_notifications', 1);
    }

    public function test_unregistered_walk_in_can_only_use_its_recorded_contact_email(): void
    {
        $order = $this->order(['user_id' => null, 'sales_channel' => 'walk_in', 'contact_email' => 'walk-in@example.test', 'delivery_method' => 'pickup']);
        $this->accepted();
        $this->markReady($order)->assertRedirect();
        Http::assertSent(function ($request) use ($order) {
            $this->assertSame('walk-in@example.test', $request['to'][0]['email']);
            $this->assertStringContainsString('/guest/invoices/'.$order->id, $request['htmlContent']);
            $this->assertStringContainsString('signature=', $request['htmlContent']);

            return true;
        });
    }

    public static function unsafeRecipients(): array
    {
        return [
            ['staff', 'staff@example.test', 'online'], ['admin', 'owner@example.test', 'online'],
            ['customer', "buyer@example.test\r\nBcc: attacker@example.test", 'online'],
            [null, null, 'walk_in'], [null, 'not-an-email', 'walk_in'], [null, 'guest@example.test', 'online'],
        ];
    }

    #[DataProvider('unsafeRecipients')]
    public function test_no_notification_can_go_to_internal_accounts_invalid_or_unapproved_recipients(?string $role, ?string $email, string $channel): void
    {
        $buyer = $role ? $this->actor($role) : null;
        if ($buyer) {
            $buyer->update(['email' => $email]);
        }
        $order = $this->order(['user_id' => $buyer?->id, 'contact_email' => $email, 'sales_channel' => $channel]);
        $this->markReady($order)->assertRedirect();
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertSame('skipped', OrderReadyNotification::sole()->status);
        Http::assertNothingSent();
    }

    public function test_guests_customers_unpaid_orders_and_missing_reason_cannot_trigger_ready_email(): void
    {
        $order = $this->order();
        $data = ['status' => 'ready', 'reason' => 'Order packed and checked by staff.'];
        $this->patch(route('admin.orders.status', $order), $data)->assertRedirect('/admin/login');
        $this->actingAs($order->user)->patch(route('admin.orders.status', $order), $data)->assertForbidden();
        $staff = $this->actor();
        $this->actingAs($staff)->patch(route('admin.orders.status', $order), ['status' => 'ready'])->assertSessionHasErrors('reason');
        foreach (['pending', 'partially_paid'] as $status) {
            $order->update(['payment_status' => $status]);
            $this->markReady($order, $staff)->assertStatus(422);
        }
        $this->assertSame('packing', $order->fresh()->status);
        $this->assertDatabaseCount('order_ready_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_audit_failure_rolls_back_ready_change_and_queue_without_contacting_brevo(): void
    {
        $order = $this->order();
        $this->mock(ActivityRecorder::class, fn ($mock) => $mock->shouldReceive('record')->once()->andReturnNull());
        $this->markReady($order)->assertServerError();
        $this->assertSame('packing', $order->fresh()->status);
        $this->assertDatabaseCount('order_ready_notifications', 0);
        Http::assertNothingSent();
    }

    public static function uncertainResponses(): array
    {
        return [[201, []], [200, ['messageId' => '<not-created@test>']], [408, []], [500, []], [302, []],
            [400, ['code' => 'duplicate_parameter', 'message' => 'The idempotency key has already been used.']]];
    }

    #[DataProvider('uncertainResponses')]
    public function test_uncertain_acceptance_does_not_undo_ready_and_cannot_be_automatically_or_manually_retried(int $status, array $body): void
    {
        $order = $this->order();
        $actor = $this->actor();
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::response($body, $status)]);
        $this->markReady($order, $actor)->assertRedirect();
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertSame('unknown', OrderReadyNotification::sole()->status);
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($order->id));
        $this->actingAs($actor)->post(route('admin.orders.ready-email.retry', $order), ['reason' => 'Check the previous request before any resend.'])->assertRedirect();
        Http::assertSentCount(1);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'order.ready_email_retried']);
    }

    public function test_connection_timeout_is_durably_unknown_and_does_not_interrupt_fulfillment(): void
    {
        $order = $this->order();
        Http::fake(fn () => throw new ConnectionException('Synthetic timeout'));
        $this->markReady($order)->assertRedirect()->assertSessionHas('notice');
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertSame('unknown', OrderReadyNotification::sole()->status);
        $this->assertSame(1, OrderReadyNotification::sole()->attempts);
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($order->id));
    }

    public function test_confirmed_rejection_can_be_retried_by_staff_with_reason_and_audit_but_never_automatically(): void
    {
        $order = $this->order();
        $actor = $this->actor();
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::sequence()->push(['code' => 'invalid_parameter'], 400)->push(['messageId' => '<corrected-ready@test>'], 201)]);
        $this->markReady($order, $actor)->assertRedirect();
        $notification = OrderReadyNotification::sole();
        $this->assertSame('failed', $notification->status);
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($order->id));
        $this->actingAs($actor)->post(route('admin.orders.ready-email.retry', $order), [])->assertSessionHasErrors('reason');
        Http::assertSentCount(1);
        $this->actingAs($actor)->post(route('admin.orders.ready-email.retry', $order), ['reason' => 'Corrected the sender setup after checking the rejection.'])->assertRedirect();
        $notification->refresh();
        $this->assertSame('sent', $notification->status);
        $this->assertSame(2, $notification->attempts);
        $this->assertDatabaseHas('activity_logs', ['actor_id' => $actor->id, 'action' => 'order.ready_email_retried', 'target_id' => $order->id]);
        Http::assertSentCount(2);
        $this->actingAs($actor)->post(route('admin.orders.ready-email.retry', $order), ['reason' => 'A repeated request must not send another accepted email.'])->assertRedirect();
        Http::assertSentCount(2);
    }

    public function test_parallel_reentry_and_interrupted_claim_cannot_duplicate_a_send(): void
    {
        $order = $this->order();
        $this->queue($order);
        $calls = 0;
        Http::fake(function () use ($order, &$calls) {
            $calls++;
            $this->assertSame('claimed', OrderReadyNotification::sole()->status);
            $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($order->id));

            return Http::response(['messageId' => '<one-parallel@test>'], 201);
        });
        $this->assertSame('sent', app(ReadyOrderNotifications::class)->send($order->id));
        $this->assertSame(1, $calls);
        $other = $this->order();
        $this->queue($other);
        OrderReadyNotification::where('order_id', $other->id)->update(['status' => 'claimed', 'attempted_at' => now()]);
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($other->id));
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($other->id, $this->actor(), 'Review of an interrupted send must not trigger another.'));
        $this->assertSame(1, $calls);
    }

    public function test_completed_courier_cancelled_or_no_longer_paid_queued_orders_do_not_send_stale_updates(): void
    {
        foreach ([['status' => 'out_for_delivery'], ['status' => 'completed', 'customer_confirmed_at' => now()],
            ['status' => 'cancelled'], ['payment_status' => 'partially_paid'], ['inventory_released' => true], ['payment_id' => null]] as $changes) {
            $order = $this->order();
            $this->queue($order);
            $order->update($changes);
            $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($order->id));
            $this->assertSame('skipped', OrderReadyNotification::where('order_id', $order->id)->sole()->status);
        }
        Http::assertNothingSent();
    }

    public function test_refund_hold_added_after_queue_prevents_email_and_retry(): void
    {
        $order = $this->order();
        $this->queue($order);
        Refund::create(['order_id' => $order->id, 'requested_by' => $order->user_id, 'reason' => 'Synthetic refund fixture reason.',
            'requested_amount' => 25000, 'amount' => 25000, 'status' => 'succeeded']);
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($order->id));
        $this->assertSame('skipped', OrderReadyNotification::sole()->status);
        Http::assertNothingSent();
    }

    public function test_order_change_between_claim_and_delivery_is_rechecked_before_http(): void
    {
        $order = $this->order();
        $this->queue($order);
        OrderReadyNotification::updated(function ($notification) use ($order) {
            if ($notification->status === 'claimed') {
                Order::whereKey($order->id)->update(['status' => 'out_for_delivery']);
            }
        });
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($order->id));
        $this->assertSame('skipped', OrderReadyNotification::sole()->status);
        Http::assertNothingSent();
    }

    public function test_persistence_failure_after_provider_acceptance_leaves_a_claimed_record_that_cannot_be_resent(): void
    {
        $order = $this->order();
        $this->accepted();
        OrderReadyNotification::updating(function ($notification) {
            if ($notification->status === 'sent') {
                throw new \RuntimeException('Synthetic outcome storage failure.');
            }
        });
        $this->markReady($order)->assertRedirect();
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertSame('claimed', OrderReadyNotification::sole()->status);
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($order->id));
        Http::assertSentCount(1);
    }

    public function test_reused_provider_message_id_is_not_accepted_as_a_second_delivery_receipt(): void
    {
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<duplicate-message@test>'], 201)]);
        $first = $this->order();
        $this->markReady($first)->assertRedirect();
        $second = $this->order();
        $this->markReady($second)->assertRedirect();
        $this->assertSame('sent', OrderReadyNotification::where('order_id', $first->id)->sole()->status);
        $this->assertSame('unknown', OrderReadyNotification::where('order_id', $second->id)->sole()->status);
        $this->assertSame('ready', $second->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_switch_off_keeps_order_saved_without_network_and_only_a_staff_retry_can_send_queued_email(): void
    {
        config(['services.brevo.delivery_notifications_enabled' => false]);
        $order = $this->order();
        $this->markReady($order)->assertRedirect();
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertSame('queued', OrderReadyNotification::sole()->status);
        Http::assertNothingSent();
        config(['services.brevo.delivery_notifications_enabled' => true]);
        auth()->logout();
        $this->post(route('admin.orders.ready-email.retry', $order), ['reason' => 'Readiness email setup has now been enabled.'])->assertRedirect('/admin/login');
        $this->actingAs($order->user)->post(route('admin.orders.ready-email.retry', $order), ['reason' => 'Readiness email setup has now been enabled.'])->assertForbidden();
        $this->accepted();
        $this->actingAs($this->actor())->post(route('admin.orders.ready-email.retry', $order), ['reason' => 'Readiness email setup has now been enabled.'])->assertRedirect();
        $this->assertSame('sent', OrderReadyNotification::sole()->status);
        Http::assertSentCount(1);
    }

    public function test_missing_brevo_setup_never_sends_and_existing_ready_orders_are_not_retroactively_enqueued(): void
    {
        config(['services.brevo.api_key' => null]);
        $order = $this->order();
        $this->markReady($order)->assertRedirect();
        $this->assertSame('queued', OrderReadyNotification::sole()->status);
        $old = $this->order(['status' => 'ready']);
        $this->assertSame('unavailable', app(ReadyOrderNotifications::class)->send($old->id));
        config(['services.brevo.api_key' => 'brevo-ready-test-key']);
        $this->assertSame('not_sent', app(ReadyOrderNotifications::class)->send($old->id));
        $this->assertDatabaseCount('order_ready_notifications', 1);
        Http::assertNothingSent();
    }

    public function test_staff_can_see_email_outcome_but_customer_and_guest_invoice_cannot_see_internal_retry_details(): void
    {
        $order = $this->order();
        $this->accepted();
        $this->markReady($order)->assertRedirect();
        $this->actingAs($this->actor())->get(route('orders.show', $order))->assertOk()->assertSee('Customer readiness email')->assertSee('Accepted by Brevo');
        $this->actingAs($order->user)->get(route('orders.show', $order))->assertOk()->assertDontSee('Customer readiness email')->assertDontSee('Accepted by Brevo')->assertDontSee('brevo-ready-test-key');
        $guest = $this->order(['user_id' => null, 'sales_channel' => 'walk_in']);
        $this->get(URL::temporarySignedRoute('guest.orders.show', now()->addDay(), ['order' => $guest->id]))->assertOk()->assertDontSee('Customer readiness email');
        Http::assertSentCount(1);
    }
}
