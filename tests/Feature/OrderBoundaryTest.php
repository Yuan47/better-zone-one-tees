<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderFlow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'Asia/Manila'));
        config(['shop.paymongo.mode' => 'test', 'shop.paymongo.secret' => 'sk_test_boundary',
            'shop.paymongo.webhook_secret' => 'boundary-secret', 'shop.grok.key' => 'test-grok']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function buyer(string $role = 'customer'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function variant(): Variant
    {
        $product = Product::create(['name' => 'Boundary Tee', 'slug' => 'boundary-'.uniqid(), 'category' => 'Apparel',
            'description' => 'Order boundary fixture', 'art_color' => '#941B0C']);

        return $product->variants()->create(['sku' => 'BOUNDARY-'.uniqid(), 'size' => 'M', 'color' => 'White',
            'stock' => 20, 'reserved' => 0, 'retail_price' => 10000, 'wholesale_price' => 9000, 'reseller_price' => 8000]);
    }

    private function purchase(User $buyer, bool $paid = true, array $attributes = []): Order
    {
        $variant = $this->variant();
        $order = app(OrderFlow::class)->reserve([$variant->id => 2], [
            'recipient' => 'Original recipient', 'phone' => '09123456789', 'address' => 'Private original address',
            'street_address' => '123 Private Street', 'city' => 'Manila', 'province' => 'Metro Manila',
            'postal_code' => '1000', 'country' => 'Philippines', 'lat' => '14.599500', 'lng' => '120.984200',
            'delivery_method' => 'pickup', 'shipping' => 0,
        ], $buyer->id);
        if ($paid) {
            DB::transaction(function () use ($order) {
                app(OrderFlow::class)->paid(Order::whereKey($order->id)->lockForUpdate()->firstOrFail(), 'pay_boundary_'.$order->id);
            });
        }
        $order->update($attributes);

        return $order->fresh();
    }

    private function commerceSnapshot(Order $order): array
    {
        return [
            'order' => Arr::except($order->fresh()->getAttributes(), ['status', 'updated_at']),
            'items' => $order->items()->get()->map->getAttributes()->all(),
            'inventory' => Variant::whereIn('id', $order->items()->pluck('variant_id'))->get(['id', 'stock', 'reserved'])->toArray(),
        ];
    }

    public static function storeRoles(): array
    {
        return [['staff'], ['admin']];
    }

    #[DataProvider('storeRoles')]
    public function test_valid_manual_status_change_ignores_injected_financial_customer_and_receipt_fields(string $role): void
    {
        $order = $this->purchase($this->buyer());
        $before = $this->commerceSnapshot($order);
        $actor = $this->buyer($role);
        $this->actingAs($actor)->patchJson(route('admin.orders.status', $order), [
            'status' => 'ready', 'reason' => 'The original order has been packed and checked.',
            'total' => 1, 'subtotal' => 1, 'merchandise_base' => 1, 'discount' => 19999, 'shipping' => 999,
            'quantity' => 999, 'payment_status' => 'pending', 'payment_id' => 'pay_forged', 'paid_at' => '2099-01-01',
            'user_id' => $actor->id, 'recipient' => 'Forged recipient', 'phone' => '09999999999', 'address' => 'Forged address',
            'street_address' => 'Different street', 'city' => 'Different city', 'province' => 'Different province',
            'postal_code' => '9999', 'country' => 'Different country', 'lat' => 0, 'lng' => 0,
            'delivery_method' => 'lalamove', 'checkout_session_id' => 'cs_forged', 'checkout_url' => 'https://example.test',
            'inventory_committed' => false, 'inventory_released' => true, 'customer_confirmed_at' => now()->toDateTimeString(),
            'provider_completed_at' => now()->toDateTimeString(), 'delivery_last_status' => 'COMPLETED',
            'rating' => 5, 'comment' => 'A fabricated customer review.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('ready', $order->fresh()->status);
        $this->assertSame($before, $this->commerceSnapshot($order));
        $this->assertDatabaseCount('order_reviews', 0);
        Http::assertNothingSent();
    }

    public function test_generic_order_and_payment_patch_urls_do_not_offer_a_financial_update_endpoint(): void
    {
        $buyer = $this->buyer();
        $order = $this->purchase($buyer);
        $before = $order->getAttributes();
        foreach ([$buyer, $this->buyer('staff'), $this->buyer('admin')] as $actor) {
            $this->actingAs($actor);
            foreach (['/orders/'.$order->id => 405, '/orders/'.$order->id.'/payment' => 404,
                '/admin/orders/'.$order->id.'/payment' => 404] as $url => $status) {
                $this->patchJson($url, ['payment_status' => 'paid', 'payment_id' => 'pay_client', 'total' => 1,
                    'status' => 'completed', 'customer_confirmed_at' => now()->toDateTimeString()])->assertStatus($status);
            }
        }
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_two_customers_cannot_replay_each_others_order_actions_with_forged_ownership_fields(): void
    {
        $first = $this->buyer();
        $second = $this->buyer();
        $firstOrder = $this->purchase($first, attributes: ['status' => 'ready']);
        $secondOrder = $this->purchase($second, attributes: ['status' => 'ready']);
        $snapshots = [$firstOrder->id => $this->commerceSnapshot($firstOrder), $secondOrder->id => $this->commerceSnapshot($secondOrder)];
        foreach ([[$first, $secondOrder], [$second, $firstOrder]] as [$actor, $foreign]) {
            $payload = ['user_id' => $foreign->user_id, 'order_id' => $foreign->id, 'role' => 'admin',
                'received' => true, 'rating' => 5, 'comment' => 'Received and reviewed by a different buyer.',
                'reason' => 'Another buyer is trying to request this refund.'];
            $this->actingAs($actor)->get(route('orders.show', $foreign))->assertForbidden();
            foreach (['orders.payment.check', 'orders.payment.close', 'orders.refunds.store', 'orders.receipt'] as $route) {
                // Verify ownership after the throttle window instead of relying on a rate-limit denial.
                $this->travel(61)->seconds();
                $this->postJson(route($route, $foreign), $payload)->assertForbidden();
            }
            $this->patchJson(route('admin.orders.status', $foreign), ['status' => 'ready', 'reason' => 'Forged store owner request.'] + $payload)->assertForbidden();
            $this->assertSame('ready', $foreign->fresh()->status);
            $this->assertSame($snapshots[$foreign->id], $this->commerceSnapshot($foreign));
        }
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('order_reviews', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_store_roles_cannot_pose_as_a_customer_to_confirm_even_their_own_orders(): void
    {
        foreach (['staff', 'admin'] as $role) {
            $actor = $this->buyer($role);
            $order = $this->purchase($actor, attributes: ['status' => 'ready']);
            $before = $order->getAttributes();
            $this->actingAs($actor)->postJson(route('orders.receipt', $order), [
                'received' => true, 'rating' => 5, 'comment' => 'This review is being submitted by a store role.',
                'role' => 'customer', 'side' => 'customer', 'user_id' => $actor->id,
            ])->assertForbidden();
            $this->assertSame($before, $order->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('order_reviews', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_new_checkout_cannot_rewrite_a_paid_order_referenced_by_a_stale_session_or_client_fields(): void
    {
        $buyer = $this->buyer();
        $paid = $this->purchase($buyer);
        $before = $this->commerceSnapshot($paid);
        $variant = $this->variant();
        Http::fake(['api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => ['id' => 'cs_boundary_new',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_boundary_new']]])]);
        $this->actingAs($buyer)->withSession(['pending_order' => $paid->id, 'cart' => [$variant->id => 3]])
            ->post(route('checkout.place'), ['recipient' => 'New recipient', 'phone' => '09123456789', 'delivery_method' => 'pickup',
                'order_id' => $paid->id, 'pending_order' => $paid->id, 'payment_status' => 'paid', 'payment_id' => 'pay_forged',
                'total' => 1, 'quantity' => 999])->assertRedirect('https://checkout.paymongo.com/cs_boundary_new');
        $pending = Order::whereKeyNot($paid->id)->sole();
        $this->assertSame($before, $this->commerceSnapshot($paid));
        $this->assertSame('packing', $paid->fresh()->status);
        $this->assertSame($buyer->id, $pending->user_id);
        $this->assertSame(3, $pending->quantity);
        $this->assertSame(30000, $pending->total);
        $this->assertSame('pending', $pending->payment_status);
        $this->assertNull($pending->payment_id);
        $this->assertSame(3, $variant->fresh()->reserved);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['data']['attributes']['reference_number'] === $pending->number
            && $request['data']['attributes']['line_items'][0]['amount'] === 10000 && $request['data']['attributes']['line_items'][0]['quantity'] === 3);
    }

    public function test_foreign_pending_order_in_a_reused_session_cannot_capture_the_next_buyers_checkout(): void
    {
        $foreign = $this->purchase($this->buyer(), paid: false);
        $before = $this->commerceSnapshot($foreign);
        $buyer = $this->buyer();
        $variant = $this->variant();
        Http::fake(['api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => ['id' => 'cs_boundary_own',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_boundary_own']]])]);
        $this->actingAs($buyer)->withSession(['pending_order' => $foreign->id, 'cart' => [$variant->id => 1]])
            ->post(route('checkout.place'), ['recipient' => 'Current buyer', 'phone' => '09123456789', 'delivery_method' => 'pickup',
                'user_id' => $foreign->user_id, 'order_id' => $foreign->id])->assertRedirect('https://checkout.paymongo.com/cs_boundary_own');
        $own = Order::where('user_id', $buyer->id)->sole();
        $this->assertSame($before, $this->commerceSnapshot($foreign));
        $this->assertSame('awaiting_payment', $foreign->fresh()->status);
        $this->assertSame(1, $own->quantity);
        $this->assertSame(10000, $own->total);
        $this->assertSame('cs_boundary_own', $own->checkout_session_id);
        $this->assertDatabaseCount('orders', 2);
        Http::assertSentCount(1);
    }

    public function test_order_chat_reports_the_four_customer_stages_without_exposing_other_buyers_orders(): void
    {
        $buyer = $this->buyer();
        foreach ([
            [['status' => 'packing'], 'Packing'],
            [['status' => 'delivery_attention', 'delivery_method' => 'lalamove'], 'Ready for delivery'],
            [['status' => 'out_for_delivery', 'delivery_method' => 'lalamove', 'provider_completed_at' => now()], 'With courier'],
            [['status' => 'completed', 'customer_confirmed_at' => now()], 'Delivered'],
        ] as [$attributes, $label]) {
            $order = $this->purchase($buyer, attributes: $attributes);
            $this->actingAs($buyer)->postJson(route('chat'), ['side' => 'customer', 'message' => 'Track '.$order->number])
                ->assertOk()->assertJsonPath('mode', 'store')
                ->assertJsonPath('reply', fn ($reply) => str_contains($reply, $order->number.': '.$label.'; payment paid.'))
                ->assertDontSee('delivery attention')->assertDontSee('out for delivery')->assertDontSee('completed;');
        }
        $foreign = $this->purchase($this->buyer(), attributes: ['status' => 'completed', 'customer_confirmed_at' => now()]);
        $this->postJson(route('chat'), ['side' => 'customer', 'message' => 'Track '.$foreign->number])
            ->assertOk()->assertJsonPath('mode', 'store')->assertDontSee($foreign->number)->assertDontSee('payment paid')->assertDontSee('Delivered');
        Http::assertNothingSent();
    }

    public function test_customer_chat_cannot_switch_to_staff_context_by_forging_the_side_or_role(): void
    {
        $buyer = $this->buyer();
        $foreign = $this->purchase($this->buyer());
        $this->actingAs($buyer)->postJson(route('chat'), ['side' => 'staff', 'role' => 'admin', 'user_id' => $foreign->user_id,
            'message' => 'Track '.$foreign->number])->assertForbidden();
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }

    public function test_legacy_other_status_filter_does_not_misclassify_known_courier_or_completed_records(): void
    {
        $buyer = $this->buyer();
        $unknown = $this->purchase($buyer, attributes: ['status' => 'legacy_hold']);
        $known = [];
        foreach (['to_pack', 'packing', 'ready', 'delivery_booking', 'delivery_attention', 'out_for_delivery', 'completed'] as $status) {
            $known[] = $this->purchase($buyer, attributes: ['status' => $status])->number;
        }
        $response = $this->actingAs($this->buyer('staff'))->get('/admin/orders?status=other&payment_status=paid');
        $response->assertOk()->assertSee($unknown->number);
        foreach ($known as $number) {
            $response->assertDontSee($number);
        }
        Http::assertNothingSent();
    }
}
