<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function order(string $method = 'lalamove'): Order
    {
        $user = User::factory()->create(['role' => 'customer']);
        $product = Product::create(['name' => 'Receipt Tee', 'slug' => 'receipt-'.uniqid(), 'category' => '', 'description' => 'Test clothing']);
        $variant = $product->variants()->create(['sku' => 'RECEIPT-'.uniqid(), 'size' => 'M', 'color' => 'White', 'stock' => 10, 'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
        $flow = app(OrderFlow::class);
        $order = $flow->reserve([$variant->id => 2], ['recipient' => 'Receipt Customer', 'phone' => '09123456789', 'address' => 'Test address', 'delivery_method' => $method, 'shipping' => 0], $user->id);
        DB::transaction(fn () => $flow->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_receipt_'.$order->id));
        $order->refresh()->update(['status' => $method === 'pickup' ? 'ready' : 'out_for_delivery', 'provider_completed_at' => $method === 'pickup' ? null : now(), 'delivery_last_status' => $method === 'pickup' ? null : 'COMPLETED']);

        return $order->fresh();
    }

    private function receipt(array $extra = []): array
    {
        return $extra + ['received' => '1', 'rating' => 1, 'comment' => 'The fit was disappointing, but the order arrived.'];
    }

    private function invoice(Order $order): array
    {
        return array_intersect_key($order->fresh()->getRawOriginal(), array_flip(['user_id', 'quantity', 'tier', 'subtotal', 'merchandise_base', 'discount', 'shipping', 'total', 'payment_status', 'payment_id', 'paid_at', 'inventory_committed', 'inventory_released']));
    }

    public function test_customer_receipt_requires_review_and_unlocks_refund_without_changing_payment_or_stock(): void
    {
        $order = $this->order();
        $before = $this->invoice($order);
        $stock = Variant::first()->only(['stock', 'reserved']);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/refunds', ['reason' => 'The clothing fit does not suit me.'])->assertSessionHasErrors('refund');
        $this->post('/orders/'.$order->id.'/receipt', $this->receipt(['total' => 1, 'payment_status' => 'failed', 'status' => 'cancelled']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->customer_confirmed_at);
        $this->assertSame(1, $order->review()->sole()->rating);
        $this->assertSame($before, $this->invoice($order));
        $this->assertSame($stock, Variant::first()->only(['stock', 'reserved']));
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.received', 'actor_id' => $order->user_id, 'target_id' => $order->id]);
        $this->get('/orders/'.$order->id)->assertOk()->assertSee('Customer review')->assertSee('1 / 5')->assertSee('Request a refund or return');
        $this->post('/orders/'.$order->id.'/refunds', ['reason' => 'The clothing fit does not suit me.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('refunds', ['order_id' => $order->id, 'status' => 'requested']);
        Http::assertNothingSent();
    }

    public function test_repeated_receipt_keeps_first_review_and_one_audit_event(): void
    {
        $order = $this->order();
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())->assertSessionHasNoErrors();
        $time = $order->fresh()->getRawOriginal('customer_confirmed_at');
        $this->post('/orders/'.$order->id.'/receipt', $this->receipt(['rating' => 5, 'comment' => 'This second review must never replace the first.']))->assertSessionHasNoErrors();
        $this->assertSame(1, $order->review()->sole()->rating);
        $this->assertSame($time, $order->fresh()->getRawOriginal('customer_confirmed_at'));
        $this->assertSame(1, ActivityLog::where('action', 'order.received')->count());
        $this->assertDatabaseCount('order_reviews', 1);
    }

    public static function invalidReviews(): array
    {
        return [['received', '0'], ['rating', 0], ['rating', 6], ['rating', 1.5], ['rating', 'excellent'], ['rating', null], ['comment', 'short'], ['comment', ''], ['comment', str_repeat('a', 2001)]];
    }

    #[DataProvider('invalidReviews')]
    public function test_invalid_or_missing_review_cannot_close_order(string $field, mixed $value): void
    {
        $order = $this->order();
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt([$field => $value]))->assertSessionHasErrors($field);
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertNull($order->fresh()->customer_confirmed_at);
        $this->assertDatabaseCount('order_reviews', 0);
        $this->assertSame(0, ActivityLog::where('action', 'order.received')->count());
    }

    public static function notArrived(): array
    {
        return [['packing', 'paid', true], ['ready', 'paid', true], ['delivery_booking', 'paid', true], ['out_for_delivery', 'paid', false], ['out_for_delivery', 'pending', true], ['cancelled', 'paid', true]];
    }

    #[DataProvider('notArrived')]
    public function test_unpaid_or_not_arrived_order_cannot_be_confirmed(string $status, string $payment, bool $verified): void
    {
        $order = $this->order();
        $order->update(['status' => $status, 'payment_status' => $payment, 'provider_completed_at' => $verified ? now() : null]);
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())->assertSessionHasErrors('receipt');
        $this->assertSame($status, $order->fresh()->status);
        $this->assertDatabaseCount('order_reviews', 0);
    }

    public function test_pickup_is_closed_only_by_customer_after_packing_and_required_review(): void
    {
        $order = $this->order('pickup');
        $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())->assertSessionHasNoErrors();
        $this->assertSame('Delivered', $order->fresh()->customerDeliveryLabel());
        $this->assertDatabaseCount('order_reviews', 1);
    }

    public function test_manual_change_needs_a_reason_and_replay_cannot_create_a_second_change(): void
    {
        $order = $this->order();
        $order->update(['status' => 'packing', 'provider_completed_at' => null]);
        $staff = User::factory()->create(['role' => 'staff']);
        foreach (['', '   ', 'short', str_repeat('x', 1001)] as $reason) {
            $this->actingAs($staff)->patch('/admin/orders/'.$order->id, ['status' => 'ready', 'reason' => $reason])->assertSessionHasErrors('reason');
            $this->assertSame('packing', $order->fresh()->status);
        }
        $this->assertSame(0, ActivityLog::where('action', 'order.status_changed')->count());
        $payload = ['status' => 'ready', 'reason' => 'All pieces were counted, packed and checked.'];
        $this->patch('/admin/orders/'.$order->id, $payload)->assertSessionHasNoErrors();
        $event = ActivityLog::where('action', 'order.status_changed')->sole();
        $this->assertSame($payload['reason'], $event->metadata['reason']);
        $this->assertSame('packing', $event->metadata['previous_status']);
        $this->patchJson('/admin/orders/'.$order->id, $payload)->assertUnprocessable();
        $this->assertSame(1, ActivityLog::where('action', 'order.status_changed')->count());
    }

    public static function auditFailures(): array
    {
        return [['receipt', false], ['receipt', true], ['status', false], ['status', true]];
    }

    #[DataProvider('auditFailures')]
    public function test_audit_failure_rolls_back_order_change_and_review(string $action, bool $throws): void
    {
        $order = $this->order();
        $this->mock(ActivityRecorder::class, function ($mock) use ($throws) {
            $expect = $mock->shouldReceive('record')->once();
            if ($throws) {
                $expect->andThrow(new \RuntimeException('Simulated audit outage'));
            } else {
                $expect->andReturnNull();
            }
        });
        if ($action === 'receipt') {
            $this->actingAs($order->user)->post('/orders/'.$order->id.'/receipt', $this->receipt())->assertServerError();
            $this->assertSame('out_for_delivery', $order->fresh()->status);
            $this->assertNull($order->fresh()->customer_confirmed_at);
            $this->assertDatabaseCount('order_reviews', 0);
        } else {
            $order->update(['status' => 'packing', 'provider_completed_at' => null]);
            $this->actingAs(User::factory()->create(['role' => 'staff']))->patch('/admin/orders/'.$order->id, ['status' => 'ready', 'reason' => 'All pieces were counted, packed and checked.'])->assertServerError();
            $this->assertSame('packing', $order->fresh()->status);
        }
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
