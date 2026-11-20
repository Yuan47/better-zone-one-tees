<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\DeliveryRecovery;
use App\Services\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerDeliveryRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.lalamove.mode' => 'sandbox', 'shop.lalamove.key' => 'pk_test_delivery', 'shop.lalamove.secret' => 'sk_test_delivery',
            'shop.lalamove.address' => '10 Store Street, Quezon City', 'shop.lalamove.lat' => '14.65', 'shop.lalamove.lng' => '121.03',
            'shop.lalamove.phone' => '+639123456789', 'shop.lalamove.service' => 'MOTORCYCLE']);
        Http::preventStrayRequests();
    }

    private function order(array $changes = []): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $product = Product::create(['name' => 'Customer delivery tee', 'slug' => 'delivery-refresh-'.uniqid(), 'category' => '', 'description' => 'Clothing']);
        $variant = $product->variants()->create(['sku' => 'DELIVERY-'.uniqid(), 'size' => 'M', 'color' => 'White',
            'stock' => 20, 'retail_price' => 15000, 'wholesale_price' => 14000, 'reseller_price' => 13000]);
        $flow = app(OrderFlow::class);
        $order = $flow->reserve([$variant->id => 2], ['recipient' => 'Delivery Buyer', 'phone' => '09123456780',
            'address' => '20 Buyer Street, Quezon City, Metro Manila, 1100, Philippines',
            'delivery_method' => 'lalamove', 'lat' => '14.676', 'lng' => '121.0437', 'shipping' => 10000], $customer->id);
        DB::transaction(fn () => $flow->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_refresh_'.$order->id));
        $order->refresh()->update(['status' => 'ready']);
        $recovery = app(DeliveryRecovery::class);
        $order = $recovery->beginBooking($order->fresh());
        $recovery->acceptBooking($order, ['orderId' => '107900701184', 'status' => 'ASSIGNING_DRIVER', 'shareLink' => 'https://share.lalamove.com/test']);
        if ($changes) {
            $order->refresh()->update($changes);
        }

        return $order->fresh();
    }

    private function provider(Order $order, string $status = 'COMPLETED'): array
    {
        return ['orderId' => $order->delivery_id, 'status' => $status, 'shareLink' => 'https://share.lalamove.com/test',
            'metadata' => ['order_number' => $order->number], 'stops' => $order->delivery_booking_context['stops']];
    }

    private function refreshUrl(Order $order): string
    {
        return route('orders.delivery.refresh', $order);
    }

    private function financial(Order $order): array
    {
        return $order->only(['payment_status', 'payment_id', 'amount_paid', 'total', 'shipping', 'merchandise_base',
            'inventory_committed', 'inventory_released']);
    }

    public function test_customer_can_verify_their_attached_delivery_and_then_confirm_receipt_without_financial_or_stock_changes(): void
    {
        $order = $this->order();
        $before = $this->financial($order);
        $stock = Variant::first()->only(['stock', 'reserved']);
        Http::fake(['*/v3/orders/*' => Http::response(['data' => $this->provider($order)])]);
        $this->actingAs($order->user);
        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('Check latest delivery status')->assertDontSee('action="'.route('orders.receipt', $order).'"', false);
        Http::assertNothingSent();

        $this->from(route('orders.show', $order))->post($this->refreshUrl($order), ['delivery_id' => 'foreign-delivery'])
            ->assertRedirect(route('orders.show', $order).'#order-receipt')->assertSessionHasNoErrors()
            ->assertSessionHas('notice', fn ($notice) => str_contains($notice, 'Courier completed'));
        $order->refresh();
        $this->assertSame('107900701184', $order->delivery_id);
        $this->assertSame('COMPLETED', $order->delivery_last_status);
        $this->assertNotNull($order->provider_completed_at);
        $this->assertNull($order->customer_confirmed_at);
        $this->assertSame('out_for_delivery', $order->status);
        $this->assertDatabaseCount('order_reviews', 0);
        $this->assertSame($before, $this->financial($order));
        $this->assertSame($stock, Variant::first()->only(['stock', 'reserved']));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/orders/107900701184') && $request->method() === 'GET');

        $this->get(route('orders.show', $order))->assertOk()->assertSee('Courier completed · confirm receipt')
            ->assertSee('action="'.route('orders.receipt', $order).'"', false)
            ->assertDontSee('Check latest delivery status');
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.delivery_reconciled', 'actor_id' => $order->user_id, 'actor_role' => 'customer']);

        $this->post(route('orders.receipt', $order), ['rating' => 5, 'received' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->customer_confirmed_at);
        $this->assertSame($before, $this->financial($order->fresh()));
        $this->assertSame($stock, Variant::first()->only(['stock', 'reserved']));
        $this->assertSame('', $order->review()->sole()->comment);
    }

    public function test_guests_and_foreign_or_store_roles_cannot_check_a_customers_delivery(): void
    {
        $order = $this->order();
        Http::fake();
        $this->post($this->refreshUrl($order))->assertRedirect(route('login'));
        $this->postJson($this->refreshUrl($order))->assertUnauthorized();
        foreach (['customer', 'staff', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->post($this->refreshUrl($order))->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_delivery_attention_with_completion_evidence_is_reverified_before_unlocking_receipt(): void
    {
        $order = $this->order(['status' => 'delivery_attention', 'provider_completed_at' => now(), 'delivery_last_status' => 'COMPLETED']);
        Http::fake(['*/v3/orders/*' => Http::response(['data' => $this->provider($order)])]);
        $this->actingAs($order->user)->post($this->refreshUrl($order))->assertSessionHasNoErrors()
            ->assertRedirect(route('orders.show', $order).'#order-receipt');
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertNull($order->fresh()->customer_confirmed_at);
        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('action="'.route('orders.receipt', $order).'"', false);
        Http::assertSentCount(1);
    }

    public static function unavailable(): array
    {
        return [
            'unpaid' => [['payment_status' => 'pending']],
            'no payment reference' => [['payment_id' => null]],
            'pickup' => [['delivery_method' => 'pickup']],
            'no booking' => [['delivery_id' => null]],
            'packing' => [['status' => 'packing']],
            'uncertain booking' => [['status' => 'delivery_booking']],
            'cancelled' => [['status' => 'cancelled']],
            'stock released' => [['inventory_released' => true]],
            'stock not committed' => [['inventory_committed' => false]],
            'walk-in' => [['sales_channel' => 'walk_in']],
            'customer already confirmed' => [['status' => 'completed', 'customer_confirmed_at' => '2026-11-20 01:00:00']],
        ];
    }

    #[DataProvider('unavailable')]
    public function test_unavailable_orders_are_blocked_before_any_provider_request(array $changes): void
    {
        $order = $this->order($changes);
        $before = $order->getRawOriginal();
        Http::fake();
        $this->actingAs($order->user)->post($this->refreshUrl($order))->assertForbidden();
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_provider_in_progress_keeps_receipt_blocked_and_shows_a_plain_explanation(): void
    {
        $order = $this->order();
        Http::fake(['*/v3/orders/*' => Http::response(['data' => $this->provider($order, 'PICKED_UP')])]);
        $this->actingAs($order->user)->post($this->refreshUrl($order))->assertSessionHasNoErrors()
            ->assertSessionHas('notice', fn ($notice) => str_contains($notice, 'delivery in progress'));
        $this->assertNull($order->fresh()->provider_completed_at);
        $this->assertNull($order->fresh()->customer_confirmed_at);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Check latest delivery status')
            ->assertDontSee('action="'.route('orders.receipt', $order).'"', false);
        Http::assertSentCount(1);
    }

    public static function invalidResponses(): array
    {
        return [
            'unavailable' => [null, 503],
            'malformed' => [[], 200],
            'wrong order' => [['orderId' => 'foreign', 'status' => 'COMPLETED'], 200],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_unavailable_or_unverified_provider_responses_never_unlock_receipt_or_change_the_order(?array $data, int $status): void
    {
        $order = $this->order();
        $before = $order->getRawOriginal();
        Http::fake(['*/v3/orders/*' => Http::response(['data' => $data], $status)]);
        $this->actingAs($order->user)->post($this->refreshUrl($order))->assertSessionHasErrors('delivery')
            ->assertRedirect(route('orders.show', $order).'#order-receipt');
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('order_reviews', 0);
        // A browser retains this cookie when following the redirect and reading its flashed errors.
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('orders.show', $order))->assertOk()
            ->assertSeeInOrder(['id="order-receipt"', 'role="alert"', 'Lalamove could not'], false)
            ->assertSee('Check latest delivery status')
            ->assertDontSee('action="'.route('orders.receipt', $order).'"', false);
        Http::assertSentCount(1);
    }

    public function test_missing_connection_and_already_verified_completion_do_not_call_the_provider(): void
    {
        $order = $this->order();
        config(['shop.lalamove.key' => '']);
        Http::fake();
        $this->actingAs($order->user)->post($this->refreshUrl($order))->assertSessionHasErrors('delivery');
        $order->update(['provider_completed_at' => now(), 'delivery_last_status' => 'COMPLETED']);
        $this->post($this->refreshUrl($order))->assertSessionHasNoErrors()
            ->assertSessionHas('notice', fn ($notice) => str_contains($notice, 'Courier completed'));
        $this->assertNull($order->fresh()->customer_confirmed_at);
        Http::assertNothingSent();
    }

    public function test_customer_refresh_is_rate_limited_and_cannot_recover_or_book_deliveries(): void
    {
        $order = $this->order();
        Http::fake(['*/v3/orders/*' => Http::response(['data' => $this->provider($order, 'ON_GOING')])]);
        $this->actingAs($order->user);
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post($this->refreshUrl($order))->assertSessionHasNoErrors();
        }
        $this->post($this->refreshUrl($order))->assertTooManyRequests();
        foreach (['delivery', 'delivery/refresh', 'delivery/recover'] as $action) {
            $this->post('/admin/orders/'.$order->id.'/'.$action, ['delivery_id' => '107900701184'])->assertForbidden();
        }
        Http::assertSentCount(6);
        $this->assertSame('107900701184', $order->fresh()->delivery_id);
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertNull($order->fresh()->customer_confirmed_at);
    }

    public function test_staff_and_customer_see_prominent_completed_courier_guidance_without_premature_delivery(): void
    {
        $order = $this->order(['provider_completed_at' => now(), 'delivery_last_status' => 'COMPLETED']);
        $this->actingAs($order->user)->get(route('orders.show', $order))->assertOk()
            ->assertSee('Courier completed · confirm receipt')->assertSee('href="#order-receipt"', false);
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get(route('orders.show', $order))->assertOk()
            ->assertSee('Courier completed · awaiting customer confirmation')->assertSee('staff cannot confirm on their behalf.')
            ->assertDontSee('action="'.route('orders.receipt', $order).'"', false);
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        Http::assertNothingSent();
    }
}

